<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Closure;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
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
                $position = $manager->getNextPositionForUpdate($allocator, $this->scopedConfig(), 'budget');
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
                    $this->assertExhausted($failure, 64);
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
