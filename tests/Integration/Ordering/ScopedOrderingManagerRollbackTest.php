<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingFailureInjector;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * B-04/B-05 owned-path rollback on a real server-raised failure, ported from legacy
 * tests/Integration/Pdo/Ordering/ScopedOrderingManagerRollbackTest.php (1f6a7aa), with the
 * trigger-based failure injector replaced by a CHECK-constraint one that needs no elevated
 * privilege (see OrderingFailureInjector).
 */
final class ScopedOrderingManagerRollbackTest extends OrderingIntegrationTestCase
{
    private ScopedOrderingManager $manager;

    private PDO $controlPdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ScopedOrderingManager();
        $this->controlPdo = $this->connect();
    }

    public function testMissingTableFailureRethrowsPdoExceptionAndClosesTransactionWithoutChangingRows(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->reader()->globalOrdersById();

        $thrown = $this->captureMoveFailure(
            new ScopedOrderingConfig(table: $this->globalTable . '_missing'),
            null,
            $ids[0],
            2,
        );

        self::assertInstanceOf(PDOException::class, $thrown);
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->reader($this->controlPdo)->globalOrdersById());
    }

    public function testGlobalUpwardMoveFailureAfterRangeShiftRollsBackAllRows(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->reader()->globalOrdersById();
        $failureInjector = new OrderingFailureInjector($this->controlPdo);
        $constraint = $failureInjector->createTargetUpdateFailure($this->globalTable, $ids[3], 2);

        try {
            $thrown = $this->captureMoveFailure($this->globalConfig(), null, $ids[3], 2);

            self::assertInstanceOf(PDOException::class, $thrown);
            self::assertFalse($this->pdo()->inTransaction());
            self::assertSame([$ids[0] => 1, $ids[1] => 2, $ids[2] => 3, $ids[3] => 4], $before);
            self::assertSame($before, $this->reader($this->controlPdo)->globalOrdersById());
        } finally {
            $failureInjector->dropConstraint($this->globalTable, $constraint);
        }
    }

    public function testGlobalDownwardMoveFailureAfterRangeShiftRollsBackAllRows(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->reader()->globalOrdersById();
        $failureInjector = new OrderingFailureInjector($this->controlPdo);
        $constraint = $failureInjector->createTargetUpdateFailure($this->globalTable, $ids[0], 4);

        try {
            $thrown = $this->captureMoveFailure($this->globalConfig(), null, $ids[0], 4);

            self::assertInstanceOf(PDOException::class, $thrown);
            self::assertFalse($this->pdo()->inTransaction());
            self::assertSame([$ids[0] => 1, $ids[1] => 2, $ids[2] => 3, $ids[3] => 4], $before);
            self::assertSame($before, $this->reader($this->controlPdo)->globalOrdersById());
        } finally {
            $failureInjector->dropConstraint($this->globalTable, $constraint);
        }
    }

    public function testScopedMoveFailureAfterRangeShiftRollsBackScopeAndLeavesUnrelatedScopeUnchanged(): void
    {
        $scopeAIds = $this->insertScopedOrders('scope-a', 1, 2, 3, 4);
        $scopeBIds = $this->insertScopedOrders('scope-b', 10, 20);
        $scopeABefore = $this->reader()->scopedOrdersById('scope-a');
        $scopeBBefore = $this->reader()->scopedOrdersById('scope-b');
        $failureInjector = new OrderingFailureInjector($this->controlPdo);
        $constraint = $failureInjector->createTargetUpdateFailure($this->scopedTable, $scopeAIds[3], 2);

        try {
            $thrown = $this->captureMoveFailure($this->scopedConfig(), 'scope-a', $scopeAIds[3], 2);

            self::assertInstanceOf(PDOException::class, $thrown);
            self::assertFalse($this->pdo()->inTransaction());
            self::assertSame([$scopeAIds[0] => 1, $scopeAIds[1] => 2, $scopeAIds[2] => 3, $scopeAIds[3] => 4], $scopeABefore);
            self::assertSame([$scopeBIds[0] => 10, $scopeBIds[1] => 20], $scopeBBefore);
            self::assertSame($scopeABefore, $this->reader($this->controlPdo)->scopedOrdersById('scope-a'));
            self::assertSame($scopeBBefore, $this->reader($this->controlPdo)->scopedOrdersById('scope-b'));
        } finally {
            $failureInjector->dropConstraint($this->scopedTable, $constraint);
        }
    }

    public function testConfiguredTimestampUpdateRollsBackWithTheOrderingMutation(): void
    {
        $target = $this->fixture->insertScoped('scope-a', 1, 'target', null, '2026-01-01 00:00:00');
        $other = $this->fixture->insertScoped('scope-a', 2, 'other', null, '2026-01-01 00:00:00');
        $before = $this->reader()->scopedOrdersById('scope-a');
        $failureInjector = new OrderingFailureInjector($this->controlPdo);
        $constraint = $failureInjector->createTargetUpdateFailure($this->scopedTable, $other, 1);

        try {
            $thrown = $this->captureMoveFailure(
                new ScopedOrderingConfig(
                    table: $this->scopedTable,
                    scopeColumn: 'scope_key',
                    updatedAtColumn: 'updated_at',
                ),
                'scope-a',
                $other,
                1,
                '2026-01-03 12:34:56',
            );

            self::assertInstanceOf(PDOException::class, $thrown);
            self::assertFalse($this->pdo()->inTransaction());
            self::assertSame($before, $this->reader($this->controlPdo)->scopedOrdersById('scope-a'));

            $timestamp = $this->controlPdo->query(
                'SELECT `updated_at` FROM `' . $this->scopedTable . '` WHERE `id` = ' . $other,
            );
            self::assertNotFalse($timestamp);
            self::assertSame('2026-01-01 00:00:00', $timestamp->fetchColumn());
        } finally {
            $failureInjector->dropConstraint($this->scopedTable, $constraint);
        }
    }

    #[DataProvider('failedWriteModes')]
    public function testWriteFailurePreservesOrderingAndTransactionOwnership(
        bool $callerOwned,
        int $errorMode,
        bool $duringShift,
    ): void {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->reader()->globalOrdersById();
        $injector = new OrderingFailureInjector($this->controlPdo);
        // An upward move sends the row at 2 to 3 before moving the target from 4 to 2.
        $constraint = $injector->createTargetUpdateFailure(
            $this->globalTable,
            $duringShift ? $ids[1] : $ids[3],
            $duringShift ? 3 : 2,
        );
        $pdo = $this->pdo();
        $priorWrite = null;

        try {
            self::assertTrue($pdo->setAttribute(PDO::ATTR_ERRMODE, $errorMode));
            if ($callerOwned) {
                self::assertTrue($pdo->beginTransaction());
                $priorWrite = $this->fixture->insertScoped('prior-work', 1);
            }

            $failure = $this->captureMoveFailure($this->globalConfig(), null, $ids[3], 2);

            if ($errorMode === PDO::ERRMODE_EXCEPTION) {
                self::assertInstanceOf(PDOException::class, $failure);
            } else {
                self::assertInstanceOf(OrderingExecutionException::class, $failure);
                $operation = $duringShift ? 'shiftOrdersUp' : 'updateTargetOrder';
                self::assertSame(sprintf('Failed to execute the ordering statement for %s.', $operation), $failure->getMessage());
            }
            self::assertSame($callerOwned, $pdo->inTransaction());
            self::assertSame($before, $this->reader()->globalOrdersById());
            if ($priorWrite !== null) {
                self::assertSame([$priorWrite => 1], $this->reader()->scopedOrdersById('prior-work'));
                self::assertTrue($pdo->commit());
                self::assertSame([$priorWrite => 1], $this->reader($this->controlPdo)->scopedOrdersById('prior-work'));
            }
            self::assertSame($before, $this->reader($this->controlPdo)->globalOrdersById());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $injector->dropConstraint($this->globalTable, $constraint);
        }
    }

    public function testOutOfRangeStoredOrderDoesNotReportASuccessfulMove(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('ALTER TABLE `' . $this->globalTable . '` MODIFY `display_order` BIGINT UNSIGNED NOT NULL');
        $pdo->exec('INSERT INTO `' . $this->globalTable . '` (`display_order`, `label`) VALUES (18446744073709551615, \'wide\')');
        $id = (int) $pdo->lastInsertId();

        $failure = $this->captureMoveFailure($this->globalConfig(), null, $id, PHP_INT_MAX);

        self::assertInstanceOf(OrderingExecutionException::class, $failure);
        self::assertSame('The ordering result is outside the PHP integer range.', $failure->getMessage());
        self::assertFalse($pdo->inTransaction());
        $read = $this->controlPdo->query('SELECT CAST(`display_order` AS CHAR) FROM `' . $this->globalTable . '`');
        self::assertNotFalse($read);
        self::assertSame('18446744073709551615', $read->fetchColumn());
    }

    /** @return iterable<string, array{bool, int, bool}> */
    public static function failedWriteModes(): iterable
    {
        yield 'silent target update, package-owned' => [false, PDO::ERRMODE_SILENT, false];
        yield 'silent target update, caller-owned' => [true, PDO::ERRMODE_SILENT, false];
        yield 'silent shift, package-owned' => [false, PDO::ERRMODE_SILENT, true];
        yield 'silent shift, caller-owned' => [true, PDO::ERRMODE_SILENT, true];
        yield 'throwing shift, package-owned' => [false, PDO::ERRMODE_EXCEPTION, true];
        yield 'throwing shift, caller-owned' => [true, PDO::ERRMODE_EXCEPTION, true];
    }

    private function captureMoveFailure(
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue = null,
    ): Throwable {
        try {
            $this->manager->moveWithinScope(
                $this->pdo(),
                $config,
                $scopeValue,
                $id,
                $newOrder,
                $updatedAtValue,
            );
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected moveWithinScope() to throw.');
    }

    /**
     * @return list<int>
     */
    private function insertGlobalOrders(int ...$orders): array
    {
        $ids = [];
        foreach ($orders as $order) {
            $ids[] = $this->fixture->insertGlobal($order);
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function insertScopedOrders(int|string|null $scopeValue, int ...$orders): array
    {
        $ids = [];
        foreach ($orders as $order) {
            $ids[] = $this->fixture->insertScoped($scopeValue, $order);
        }

        return $ids;
    }
}
