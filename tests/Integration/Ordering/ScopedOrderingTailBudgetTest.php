<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Closure;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\Support\ObservedTailStatement;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScopedOrderingTailBudgetTest extends OrderingIntegrationTestCase
{
    /** @return iterable<string, array{bool, int, int, ?int, bool}> */
    public static function defaultSchedules(): iterable
    {
        foreach (['native' => false, 'emulated' => true] as $mode => $emulated) {
            yield "$mode quiet" => [$emulated, 0, 2, 3, false];
            yield "$mode stable on the last read" => [$emulated, 62, 64, 65, false];
            yield "$mode needs a 65th read" => [$emulated, 63, 64, null, false];
            yield "$mode last close fails" => [$emulated, 63, 64, null, true];
        }
    }

    #[DataProvider('defaultSchedules')]
    public function testDefaultLimitWithScheduledInnoDbAppends(
        bool $emulated,
        int $appends,
        int $readCount,
        ?int $expected,
        bool $closeFails,
    ): void {
        $this->assertScheduledTail($emulated, $appends, $readCount, $expected, $closeFails);
    }

    /** @return iterable<string, array{bool, int, int, int, ?int}> */
    public static function customSchedules(): iterable
    {
        foreach (['native' => false, 'emulated' => true] as $mode => $emulated) {
            yield "$mode two reads are sufficient" => [$emulated, 2, 0, 2, 3];
            yield "$mode requires a third read" => [$emulated, 2, 1, 2, null];
            yield "$mode stable on read 512" => [$emulated, 512, 510, 512, 513];
            yield "$mode requires read 513" => [$emulated, 512, 511, 512, null];
        }
    }

    #[DataProvider('customSchedules')]
    public function testNamedBudgetControlsTheSameInnoDbReads(
        bool $emulated,
        int $budget,
        int $appends,
        int $readCount,
        ?int $expected,
    ): void {
        $this->assertScheduledTail($emulated, $appends, $readCount, $expected, false, $budget);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidBudgets(): iterable
    {
        yield 'below minimum' => [1];
        yield 'above maximum' => [513];
    }

    #[DataProvider('invalidBudgets')]
    public function testInvalidBudgetIsRejectedBeforePreparingSql(int $budget): void
    {
        $allocator = $this->connectFaultInjecting();
        $allocator->beginTransaction();
        $allocator->failPrepare('/./');
        try {
            (new ScopedOrderingManager())->getNextPositionForUpdate($allocator, $this->scopedConfig(), 'budget', maxTailReads: $budget);
            self::fail('An out-of-range read budget must fail before preparing SQL.');
        } catch (InvalidOrderingOperationException $failure) {
            self::assertSame('The ordering tail-read limit must be between 2 and 512.', $failure->getMessage());
            self::assertTrue($allocator->inTransaction());
            self::assertSame([], $allocator->executed);
        } finally {
            $allocator->rollBack();
        }
    }

    public function testEmptyScopeWithMinimumBudgetAndLegacyNamedCall(): void
    {
        $allocator = $this->allocator(false);
        $tails = [];
        $this->observe($allocator, function (int $read, string $tail, string $sql, ?array $params) use (&$tails): bool {
            $this->assertScopedRead($sql, $params);
            $tails[] = $tail;

            return true;
        });
        $allocator->beginTransaction();
        try {
            $manager = new ScopedOrderingManager();
            self::assertSame(1, $manager->getNextPositionForUpdate(pdo: $allocator, config: $this->scopedConfig(), scopeValue: 'budget'));
            self::assertSame(1, $manager->getNextPositionForUpdate(pdo: $allocator, config: $this->scopedConfig(), scopeValue: 'budget', maxTailReads: 2));
            self::assertSame(['EOF', 'EOF', 'EOF', 'EOF'], $tails);
        } finally {
            $allocator->rollBack();
        }
    }

    public function testStableIntegerMaximumOnFinalReadKeepsOverflowFailure(): void
    {
        $this->pdo()->exec("ALTER TABLE `{$this->scopedTable}` MODIFY `display_order` BIGINT NOT NULL");
        $this->fixture->insertScoped('budget', PHP_INT_MAX);
        $allocator = $this->allocator(false);
        $reads = [];
        $this->observe($allocator, function (int $read, string $tail, string $sql, ?array $params) use (&$reads): bool {
            $this->assertScopedRead($sql, $params);
            $reads[] = $tail;

            return true;
        });
        $allocator->beginTransaction();
        try {
            (new ScopedOrderingManager())->getNextPositionForUpdate($allocator, $this->scopedConfig(), 'budget', maxTailReads: 2);
            self::fail('A position greater than PHP_INT_MAX must fail.');
        } catch (OrderingExecutionException $failure) {
            self::assertSame('The next ordering position is outside the PHP integer range.', $failure->getMessage());
            self::assertSame([], $failure->getMeta());
            self::assertSame([(string) PHP_INT_MAX, (string) PHP_INT_MAX], $reads);
            self::assertTrue($allocator->inTransaction());
        } finally {
            $allocator->rollBack();
        }
    }

    public function testRealLockTimeoutOnLastReadKeepsDriverError(): void
    {
        $this->fixture->insertScoped('budget', 2);
        $allocator = $this->allocator(false);
        $allocator->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $writer = $this->pdo();
        $this->observe($allocator, function (int $read, string $tail, string $sql, ?array $params) use ($writer): bool {
            $this->assertScopedRead($sql, $params);
            self::assertSame(1, $read);
            self::assertSame('2', $tail);
            $id = $this->fixture->insertScoped('budget', 3);
            $writer->beginTransaction();
            $writer->exec("UPDATE `{$this->scopedTable}` SET label = 'held' WHERE id = $id");

            return true;
        });
        $allocator->beginTransaction();
        try {
            $failure = self::failureOf(fn(): int => (new ScopedOrderingManager())->getNextPositionForUpdate(
                $allocator,
                $this->scopedConfig(),
                'budget',
                maxTailReads: 2,
            ));
            self::assertSame(1205, self::driverCode($failure));
            self::assertTrue($allocator->inTransaction());
        } finally {
            $allocator->rollBack();
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }
    }

    private function assertScheduledTail(
        bool $emulated,
        int $appends,
        int $readCount,
        ?int $expected,
        bool $closeFails,
        ?int $budget = null,
    ): void {
        $this->fixture->insertScoped('budget', 1);
        $lockedId = $this->fixture->insertScoped('budget', 2);
        $marker = $this->createTable($this->pdo());
        $allocator = $this->allocator($emulated);
        $reads = [];
        $this->observe($allocator, function (int $read, string $tail, string $sql, ?array $params) use ($appends, $readCount, $closeFails, &$reads): bool {
            $this->assertScopedRead($sql, $params);
            $reads[] = (int) $tail;
            if ($read <= $appends) {
                $this->fixture->insertScoped('budget', $read + 2);
            }

            return !$closeFails || $read !== $readCount;
        });

        $allocator->beginTransaction();
        try {
            $allocator->exec("INSERT INTO `$marker` (id, v) VALUES (1, 7)");
            $manager = new ScopedOrderingManager();
            $position = null;
            $failure = null;
            try {
                $position = $budget === null
                    ? $manager->getNextPositionForUpdate($allocator, $this->scopedConfig(), 'budget')
                    : $manager->getNextPositionForUpdate($allocator, $this->scopedConfig(), 'budget', maxTailReads: $budget);
            } catch (OrderingExecutionException $error) {
                $failure = $error;
            }

            if ($expected === null) {
                self::assertInstanceOf(
                    OrderingExecutionException::class,
                    $failure,
                    sprintf('Expected exhaustion after %d reads, got position %s after %d reads.', $readCount, (string) $position, count($reads)),
                );
                if ($closeFails) {
                    self::assertSame('Failed to close the ordering result for getNextPositionForUpdate.', $failure->getMessage());
                    self::assertSame([], $failure->getMeta());
                } else {
                    $this->assertExhausted($failure, $budget ?? 64);
                }
            } else {
                self::assertNull($failure);
            }
            self::assertSame($expected, $position);
            self::assertSame($readCount, count($reads));
            $tails = range(2, 2 + $appends);
            if ($expected !== null) {
                $tails[] = 2 + $appends;
            }
            self::assertSame($tails, $reads);
            self::assertTrue($allocator->inTransaction());
            self::assertSame([], $this->values($this->pdo(), $marker));
            if ($expected === null) {
                $this->assertTailLockHeld($lockedId);
            }
            $allocator->commit();
            self::assertSame([7], $this->values($this->pdo(), $marker));
            self::assertSame('2', $this->readTailLock($lockedId));
        } finally {
            if ($allocator->inTransaction()) {
                $allocator->rollBack();
            }
        }
    }

    private function allocator(bool $emulated): PDO
    {
        $pdo = $this->connect();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->arrangeLatestCommittedStateGuarantee($pdo);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);

        return $pdo;
    }

    /** @param Closure(int, string, string, array<mixed>|null): bool $afterClose */
    private function observe(PDO $pdo, Closure $afterClose): void
    {
        self::assertTrue($pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ObservedTailStatement::class, [$afterClose]]));
    }

    /** @param array<mixed>|null $params */
    private function assertScopedRead(string $sql, ?array $params): void
    {
        self::assertSame(
            "SELECT `display_order` FROM `{$this->scopedTable}` WHERE `scope_key` = :scope_value AND `deleted_at` IS NULL "
            . 'ORDER BY `display_order` DESC, `id` DESC LIMIT 1 FOR UPDATE',
            preg_replace('/\s+/', ' ', $sql),
        );
        self::assertSame(['scope_value' => 'budget'], $params);
    }

    private function assertExhausted(OrderingExecutionException $failure, int $budget): void
    {
        self::assertSame('Unable to confirm a stable ordering tail within the read budget.', $failure->getMessage());
        self::assertSame(ErrorCodeEnum::MAATIFY_ERROR, $failure->getErrorCode());
        self::assertSame(['reason' => 'TAIL_READ_LIMIT_EXCEEDED', 'maxTailReads' => $budget], $failure->getMeta());
    }

    private function readTailLock(int $id): string
    {
        $observer = $this->pdo();
        $observer->beginTransaction();
        try {
            return $this->scalar($observer, "SELECT display_order FROM `{$this->scopedTable}` WHERE id = $id FOR UPDATE NOWAIT");
        } finally {
            $observer->rollBack();
        }
    }

    private function assertTailLockHeld(int $id): void
    {
        $error = self::failureOf(fn(): string => $this->readTailLock($id));
        self::assertSame(self::engine() === 'mariadb' ? 1205 : 3572, self::driverCode($error));
    }
}
