<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingFixture;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingStateReader;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B-01/B-02 regression protection for getNextPositionForUpdate() inside a caller-owned
 * transaction whose REPEATABLE READ snapshot is older than another connection's commit, and
 * for getNextPosition() staying a plain, lock-free read. Ported from legacy
 * tests/Integration/Pdo/Ordering/ScopedOrderingManagerStaleSnapshotTest.php (1f6a7aa), adapted
 * to run-unique tables and to PartnerProcess instead of raw proc_open plumbing.
 */
final class ScopedOrderingManagerStaleSnapshotTest extends OrderingIntegrationTestCase
{
    private const LOCK_WAIT_TIMEOUT_ERROR = 1205;
    private const DEADLOCK_ERROR = 1213;
    private const PARTNER_READ_TIMEOUT_SECONDS = 30.0;

    private ScopedOrderingManager $manager;
    private PDO $otherPdo;
    private OrderingFixture $otherFixture;
    private OrderingStateReader $otherReader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->arrangeLatestCommittedStateGuarantee($this->pdo());

        $this->manager = new ScopedOrderingManager();
        $this->otherPdo = $this->connect();
        $this->otherPdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->otherPdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->arrangeLatestCommittedStateGuarantee($this->otherPdo);
        $this->otherFixture = new OrderingFixture($this->otherPdo, $this->globalTable, $this->scopedTable);
        $this->otherReader = new OrderingStateReader($this->otherPdo, $this->globalTable, $this->scopedTable);
    }

    protected function tearDown(): void
    {
        if ($this->otherPdo->inTransaction()) {
            $this->otherPdo->rollBack();
        }

        parent::tearDown();
    }

    public function testScopedNextPositionSeesRowCommittedAfterCallerSnapshot(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        $this->pdo()->rollBack();
    }

    public function testGlobalNextPositionSeesRowCommittedAfterCallerSnapshot(): void
    {
        $this->fixture->insertGlobal(1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertGlobal(2);
        $this->otherFixture->insertGlobal(3);

        self::assertSame(4, $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig()));
        $this->pdo()->rollBack();
    }

    public function testNullableScopeNextPositionSeesRowCommittedAfterCallerSnapshot(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped(null, 1);
        $this->otherFixture->insertScoped('scope-a', 7);

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->nullableScopedConfig(), null));
        $this->pdo()->rollBack();
    }

    public function testEmptyScopeStillReturnsOneInsideCallerTransaction(): void
    {
        $this->fixture->insertScoped('other-scope', 6);
        $this->beginTransactionWithSnapshot();

        self::assertSame(1, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(1, $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig()));
        $this->pdo()->rollBack();
    }

    public function testNextPositionIgnoresSoftDeletedRowsCommittedAfterCallerSnapshot(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 2);
        $this->otherFixture->insertScoped('scope-a', 9, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(3, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        $this->pdo()->rollBack();
    }

    public function testNextPositionIncludesSoftDeletedRowsWhenFilteringIsDisabledInsideCallerTransaction(): void
    {
        $this->fixture->insertGlobal(2);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertGlobal(9, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(10, $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfigWithoutSoftDeletes()));
        $this->pdo()->rollBack();
    }

    public function testNextPositionReflectsSoftDeleteCommittedAfterCallerSnapshot(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $topId = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();

        $statement = $this->otherPdo->prepare(
            'UPDATE `' . $this->scopedTable . '` SET `deleted_at` = :deleted_at WHERE `id` = :id',
        );
        self::assertNotFalse($statement);
        $statement->execute(['deleted_at' => '2026-01-01 00:00:00', 'id' => $topId]);

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        $this->pdo()->rollBack();
    }

    public function testNextPositionStaysIndependentPerScopeInsideCallerTransaction(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 4);
        $this->otherFixture->insertScoped('scope-b', 8);

        self::assertSame(5, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(9, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-b'));
        $this->pdo()->rollBack();
    }

    public function testStaleSnapshotInsertAndMoveKeepLivePositionsUniqueAndContiguous(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        $position = $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');
        $newId = $this->fixture->insertScoped('scope-a', $position);
        $this->pdo()->commit();

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $newId, 1));

        $orders = array_values($this->otherReader->scopedOrdersById('scope-a'));
        sort($orders);
        self::assertSame([1, 2], $orders);
    }

    public function testLockingReadRequiresAnActiveTransactionAndDoesNotBeginOne(): void
    {
        $this->fixture->insertGlobal(1);

        try {
            $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig());
            self::fail('A locking next-position read outside a transaction must be rejected.');
        } catch (InvalidOrderingOperationException $exception) {
            self::assertSame('A locking next-position read requires an active PDO transaction.', $exception->getMessage());
        }

        self::assertFalse($this->pdo()->inTransaction(), 'The manager must not begin a transaction.');
    }

    public function testLockingReadPreservesCallerTransactionOwnership(): void
    {
        $this->fixture->insertGlobal(1);

        $this->pdo()->beginTransaction();
        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig()));
        self::assertTrue($this->pdo()->inTransaction(), 'The manager must not commit a caller-owned transaction.');

        $this->pdo()->rollBack();
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testLockingReadRejectsInconsistentScopeUsage(): void
    {
        $this->pdo()->beginTransaction();

        try {
            $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig());
            self::fail('A scoped configuration without a scope value must be rejected.');
        } catch (InvalidOrderingOperationException) {
            self::assertTrue($this->pdo()->inTransaction());
        }

        try {
            $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig(), 'unexpected-scope');
            self::fail('A global configuration with a scope value must be rejected.');
        } catch (InvalidOrderingOperationException) {
            self::assertTrue($this->pdo()->inTransaction());
        }

        $this->pdo()->rollBack();
    }

    public function testPlainReadDoesNotBeginTransactionOutsideOne(): void
    {
        $this->fixture->insertGlobal(1);

        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testCallerTransactionHoldsLastRowLockUntilCallerEnds(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();

        self::assertSame(3, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));

        // A concurrent allocator for the same non-empty scope is serialized.
        $this->otherPdo->beginTransaction();

        try {
            $this->manager->getNextPositionForUpdate($this->otherPdo, $this->scopedConfig(), 'scope-a');
            self::fail('Concurrent allocation in a locked scope must wait for the caller transaction.');
        } catch (PDOException $exception) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
        }

        $this->otherPdo->rollBack();

        // Once the caller ends its transaction the next allocator proceeds.
        $newId = $this->fixture->insertScoped('scope-a', 3);
        $this->pdo()->commit();

        $this->otherPdo->beginTransaction();
        self::assertSame(4, $this->manager->getNextPositionForUpdate($this->otherPdo, $this->scopedConfig(), 'scope-a'));
        $this->otherPdo->rollBack();

        self::assertSame(3, $this->otherReader->scopedOrdersById('scope-a')[$newId]);
    }

    public function testCallerTransactionLockBlocksConcurrentMoveWithinScope(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $topId = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();
        $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');

        try {
            $this->manager->moveWithinScope($this->otherPdo, $this->scopedConfig(), 'scope-a', $topId, 1);
            self::fail('moveWithinScope() must wait for the allocator transaction holding the scope tail.');
        } catch (PDOException $exception) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
        }

        self::assertFalse($this->otherPdo->inTransaction());
        self::assertSame(2, $this->otherReader->scopedOrdersById('scope-a')[$topId]);
        $this->pdo()->rollBack();
    }

    public function testConcurrentMoveWithinScopeBlocksCallerTransactionAllocation(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $topId = $this->fixture->insertScoped('scope-a', 2);

        $statement = $this->pdo()->query('SELECT @@SESSION.innodb_lock_wait_timeout');
        self::assertNotFalse($statement);
        $originalLockWaitTimeout = $statement->fetchColumn();
        self::assertIsNumeric($originalLockWaitTimeout);

        try {
            $this->otherPdo->beginTransaction();
            self::assertTrue($this->manager->moveWithinScope($this->otherPdo, $this->scopedConfig(), 'scope-a', $topId, 1));

            try {
                $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

                try {
                    $this->pdo()->beginTransaction();

                    try {
                        $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');
                        self::fail('getNextPositionForUpdate() must wait for a move that holds the scope lock.');
                    } catch (PDOException $exception) {
                        self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
                    }
                } finally {
                    if ($this->pdo()->inTransaction()) {
                        $this->pdo()->rollBack();
                    }
                }
            } finally {
                $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $originalLockWaitTimeout);
            }
        } finally {
            if ($this->otherPdo->inTransaction()) {
                $this->otherPdo->rollBack();
            }
        }
    }

    public function testWaitingAllocatorSeesHigherTailCommittedWhileItWaitedUnderReadCommitted(): void
    {
        [$insertCommitted, $report] = $this->runWaitingAllocatorBehindTailInsert('READ COMMITTED');

        self::assertTrue($insertCommitted);
        self::assertSame('POSITION 4', $report);
        self::assertSame([1, 2, 3], $this->committedScopeAOrders());
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function waitingAllocatorWeights(): array
    {
        return [
            // Usually the queued allocator becomes the deadlock victim.
            'light waiting allocator' => [[]],
            // Biased towards the inserting transaction becoming the victim instead.
            'heavy waiting allocator' => [['heavy']],
        ];
    }

    /**
     * @param list<string> $waiterFlags
     */
    #[DataProvider('waitingAllocatorWeights')]
    public function testWaitingAllocatorNeverReusesAnOccupiedPositionUnderRepeatableRead(array $waiterFlags): void
    {
        [$insertCommitted, $report] = $this->runWaitingAllocatorBehindTailInsert('REPEATABLE READ', $waiterFlags);

        // Whichever transaction InnoDB picks as the deadlock victim, the committed
        // state must match the outcome: the inserted row exists only if its
        // transaction committed, and no position is occupied twice.
        $orders = $this->committedScopeAOrders();
        self::assertSame($insertCommitted ? [1, 2, 3] : [1, 2], $orders);

        if ($report === 'FAILURE ' . self::DEADLOCK_ERROR) {
            // Only one transaction can be the deadlock victim.
            self::assertTrue($insertCommitted);

            return;
        }

        // Otherwise the queued allocator must report exactly the next free position.
        self::assertSame('POSITION ' . (max($orders) + 1), $report);
    }

    public function testRepeatedLockingReadKeepsEveryTailItLockedUntilTheTransactionEnds(): void
    {
        $this->runWaitingAllocatorBehindTailInsert('READ COMMITTED', ['hold'], function (string $report): void {
            self::assertSame('POSITION 4', $report);

            // The waiting allocator locked the old tail (order 2), then the new tail
            // (order 3); both stay locked while its transaction is open. Earlier
            // rows are not part of its lock footprint.
            foreach ([2, 3] as $order) {
                try {
                    $this->probeScopeARowLock($order);
                    self::fail('The row at order ' . $order . ' must stay locked by the waiting allocator.');
                } catch (PDOException $exception) {
                    self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
                }
            }

            self::assertSame(1, $this->probeScopeARowLock(1));
        });
    }

    #[DataProvider('tailRereadSchedules')]
    public function testAllocatorConvergesWithTwoScheduledInsertAttempts(string $isolation, bool $sharedAnchor): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);
        $anchorId = $sharedAnchor ? $this->fixture->insertGlobal(1, 'parent') : null;
        $this->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $canAppend = $isolation === 'READ COMMITTED' && !$sharedAnchor;
        $tails = $canAppend ? [2, 3, 4, 4] : [2, 2];
        $flags = $anchorId === null ? ['observe'] : ['observe', $this->globalTable, (string) $anchorId];
        $partner = $this->startWaitingAllocator($isolation, 'scope-a', $flags);
        $readReports = [];
        $writeReports = [];

        try {
            foreach ($tails as $index => $tail) {
                $read = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
                self::assertStringStartsWith(sprintf('TAIL %d %d ', $index + 1, $tail), $read);
                $readReports[] = $read;

                if ($index < 2) {
                    $startedAt = hrtime(true);
                    $error = $this->attemptTailInsert($index + 3, $anchorId);
                    $writeReports[] = ['error' => $error, 'elapsedMs' => round((hrtime(true) - $startedAt) / 1_000_000, 3)];
                    self::assertSame($canAppend ? null : self::LOCK_WAIT_TIMEOUT_ERROR, $error);
                    $partner->writeLine('CONTINUE');
                }
            }

            $orders = $this->committedScopeAOrders();
            self::assertSame($canAppend ? [1, 2, 3, 4] : [1, 2], $orders);
            $allocation = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
            self::assertStringStartsWith(sprintf('POSITION %d ', max($orders) + 1), $allocation);

            if (getenv('PP_N3_MEASURE') === '1') {
                $footprint = [];
                foreach ($orders as $order) {
                    self::assertTrue($this->otherPdo->beginTransaction());
                    try {
                        $footprint[$order] = $this->probeScopeARowLock($order) === 1 ? 'free' : 'missing';
                    } catch (PDOException $exception) {
                        $footprint[$order] = $exception->errorInfo[1] ?? 'unknown';
                    } finally {
                        if ($this->otherPdo->inTransaction()) {
                            $this->otherPdo->rollBack();
                        }
                    }
                }
                fwrite(STDERR, 'N3 ' . json_encode([
                    'engine' => self::engine(),
                    'isolation' => $isolation,
                    'anchor' => $sharedAnchor,
                    'reads' => $readReports,
                    'writes' => $writeReports,
                    'allocation' => $allocation,
                    'heldRowProbes' => $footprint,
                ], JSON_THROW_ON_ERROR) . "\n");
            }

            $partner->writeLine('END');
            self::assertSame('ENDED', $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS));
        } finally {
            $partner->stop();
        }

        if ($anchorId !== null) {
            self::assertNull($this->attemptTailInsert(3, $anchorId));
            self::assertSame([1, 2, 3], $this->committedScopeAOrders());
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function tailRereadSchedules(): iterable
    {
        yield 'read committed, bypass writer' => ['READ COMMITTED', false];
        yield 'read committed, shared anchor' => ['READ COMMITTED', true];
        yield 'repeatable read, bypass writer' => ['REPEATABLE READ', false];
        yield 'repeatable read, shared anchor' => ['REPEATABLE READ', true];
    }

    private function attemptTailInsert(int $position, ?int $anchorId): ?int
    {
        $writer = $this->pdo();
        self::assertTrue($writer->beginTransaction());
        try {
            if ($anchorId !== null) {
                $this->lockParentRow($writer, $anchorId);
                $position = $this->manager->getNextPositionForUpdate($writer, $this->scopedConfig(), 'scope-a');
            }
            $this->fixture->insertScoped('scope-a', $position);
            self::assertTrue($writer->commit());

            return null;
        } catch (PDOException $failure) {
            $driverCode = $failure->errorInfo[1] ?? null;
            self::assertIsInt($driverCode);

            return $driverCode;
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }
    }

    public function testPlainReadStaysStaleWhenTheCallerLocksAfterTheSnapshotWasEstablished(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        // A locking read sees the committed row but does not refresh the snapshot.
        $statement = $this->pdo()->query(
            'SELECT `id` FROM `' . $this->scopedTable . '` WHERE `scope_key` = \'scope-a\' FOR UPDATE',
        );
        self::assertNotFalse($statement);
        self::assertCount(1, $statement->fetchAll());

        // Repeating the plain read in the same transaction (a retry) keeps returning the stale value.
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            self::assertSame(
                1,
                $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'),
                'Plain read attempt ' . $attempt,
            );
        }

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        $this->pdo()->rollBack();
    }

    /**
     * Snapshot timing only: a locking read as the first statement does not create
     * the consistent-read snapshot, so the plain read that follows sees rows
     * committed in between. The locked row (here an unrelated global row) does
     * not serialize anything for scope-a; the shared-lock tests below cover that.
     */
    public function testFirstStatementLockingReadDoesNotCreateTheSnapshot(): void
    {
        $this->fixture->insertGlobal(1);

        $this->pdo()->beginTransaction();

        $statement = $this->pdo()->query('SELECT `id` FROM `' . $this->globalTable . '` FOR UPDATE');
        self::assertNotFalse($statement);
        $statement->fetchAll();

        $this->otherFixture->insertScoped('scope-a', 1);

        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
        $this->pdo()->rollBack();
    }

    public function testPlainReadSeesLatestCommittedUnderReadCommitted(): void
    {
        $this->pdo()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->pdo()->beginTransaction();
        $this->countRows($this->scopedTable);

        $this->otherFixture->insertScoped('scope-a', 1);

        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        $this->pdo()->rollBack();
    }

    /**
     * A real caller-side serialization lock: two writers lock the same parent row
     * before touching scope-a. The competing writer is blocked behind the first,
     * and because it takes the lock before its first non-locking read, its
     * snapshot is created after the first writer committed, so the plain read is
     * current and the two positions are distinct.
     */
    public function testSharedParentLockTakenBeforeTheFirstPlainReadMakesThePlainReadCurrent(): void
    {
        $parentId = $this->fixture->insertGlobal(1, 'parent');

        $this->pdo()->beginTransaction();
        $this->lockParentRow($this->pdo(), $parentId);

        $this->otherPdo->beginTransaction();

        try {
            $this->lockParentRow($this->otherPdo, $parentId);
            self::fail('The competing writer must wait for the shared parent lock.');
        } catch (PDOException $exception) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
        }

        $this->otherPdo->rollBack();

        $first = $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a');
        self::assertSame(1, $first);
        $this->fixture->insertScoped('scope-a', $first);
        $this->pdo()->commit();

        $this->otherPdo->beginTransaction();
        $this->lockParentRow($this->otherPdo, $parentId);
        $second = $this->manager->getNextPosition($this->otherPdo, $this->scopedConfig(), 'scope-a');
        self::assertSame(2, $second);
        $this->otherFixture->insertScoped('scope-a', $second);
        $this->otherPdo->commit();

        self::assertSame([1, 2], $this->committedScopeAOrders());
    }

    /**
     * The same shared parent lock does not protect a writer whose snapshot was
     * created before it acquired the lock: its plain read stays stale and would
     * reuse position 1, while getNextPositionForUpdate() returns the free position.
     */
    public function testSharedParentLockTakenAfterTheSnapshotDoesNotMakeThePlainReadCurrent(): void
    {
        $parentId = $this->fixture->insertGlobal(1, 'parent');

        $this->pdo()->beginTransaction();
        $this->lockParentRow($this->pdo(), $parentId);

        // The competing writer creates its snapshot first, then queues for the lock.
        $this->otherPdo->beginTransaction();
        $this->establishSnapshot($this->otherPdo);

        try {
            $this->lockParentRow($this->otherPdo, $parentId);
            self::fail('The competing writer must wait for the shared parent lock.');
        } catch (PDOException $exception) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
        }

        // The lock wait timeout ends the statement only; the snapshot is kept.
        self::assertTrue($this->otherPdo->inTransaction());

        $first = $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a');
        self::assertSame(1, $first);
        $this->fixture->insertScoped('scope-a', $first);
        $this->pdo()->commit();

        $this->lockParentRow($this->otherPdo, $parentId);

        self::assertSame(1, $this->manager->getNextPosition($this->otherPdo, $this->scopedConfig(), 'scope-a'));
        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->otherPdo, $this->scopedConfig(), 'scope-a'));

        $this->otherPdo->rollBack();
    }

    public function testCallerTransactionLockDoesNotBlockRowsOfOtherScopes(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);
        $otherId = $this->fixture->insertScoped('scope-0', 1);
        $this->beginTransactionWithSnapshot();

        $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');

        $statement = $this->otherPdo->prepare(
            'UPDATE `' . $this->scopedTable . '` SET `label` = :label WHERE `id` = :id',
        );
        self::assertNotFalse($statement);
        $statement->execute(['label' => 'updated outside locked scope', 'id' => $otherId]);

        self::assertSame(1, $statement->rowCount());
        $this->pdo()->rollBack();
    }

    public function testPlainReadTakesNoLockEvenInsideCallerTransaction(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $topId = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();

        self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));

        // With the one-second lock wait timeout of the other connection, any
        // lock held by the plain read would make one of these fail with 1205.
        $update = $this->otherPdo->prepare(
            'UPDATE `' . $this->scopedTable . '` SET `label` = :label WHERE `id` = :id',
        );
        self::assertNotFalse($update);
        $update->execute(['label' => 'updated while plain read is in a transaction', 'id' => $topId]);
        self::assertSame(1, $update->rowCount());

        $this->otherFixture->insertScoped('scope-a', 3);
        self::assertSame([1, 2, 3], array_values($this->otherReader->scopedOrdersById('scope-a')));
        $this->pdo()->rollBack();
    }

    public function testPlainReadOutsideTransactionIsNotBlockedByAnotherConnectionsLock(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);

        $statement = $this->pdo()->query('SELECT @@SESSION.innodb_lock_wait_timeout');
        self::assertNotFalse($statement);
        $originalLockWaitTimeout = $statement->fetchColumn();
        self::assertIsNumeric($originalLockWaitTimeout);

        try {
            $this->otherPdo->beginTransaction();
            $this->otherPdo->query(
                'SELECT `id` FROM `' . $this->scopedTable . '`
                  WHERE `scope_key` = \'scope-a\' ORDER BY `display_order` DESC FOR UPDATE',
            );

            try {
                // Would hit the lock wait timeout if this call used a locking read.
                $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

                try {
                    self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
                    self::assertFalse($this->pdo()->inTransaction());
                } finally {
                    if ($this->pdo()->inTransaction()) {
                        $this->pdo()->rollBack();
                    }
                }
            } finally {
                $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $originalLockWaitTimeout);
            }
        } finally {
            if ($this->otherPdo->inTransaction()) {
                $this->otherPdo->rollBack();
            }
        }
    }

    /**
     * @param list<string> $flags
     */
    private function startWaitingAllocator(string $isolationLevel, string $scope, array $flags): PartnerProcess
    {
        return PartnerProcess::start([
            PHP_BINARY,
            dirname(__DIR__) . '/Support/OrderingNextPositionWaiter.php',
            $this->scopedTable,
            $isolationLevel,
            $scope,
            ...$flags,
        ]);
    }

    /**
     * Holds the scope tail in the caller transaction, queues a second allocator
     * behind it on its own connection, inserts a higher tail and commits while
     * the second allocator waits. Returns whether the insert was committed and
     * the second allocator's report. A deadlock on the inserting transaction is
     * tolerated (REPEATABLE READ only); any other failure propagates.
     *
     * $whileWaiterOpen runs after the report is read and before the waiting
     * allocator is stopped, so a test can inspect the locks it still holds when
     * it was started with the "hold" flag.
     *
     * @param list<string>                  $waiterFlags
     * @param (callable(string): void)|null $whileWaiterOpen
     *
     * @return array{0: bool, 1: string}
     */
    private function runWaitingAllocatorBehindTailInsert(
        string $isolationLevel,
        array $waiterFlags = [],
        ?callable $whileWaiterOpen = null,
    ): array {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);

        $partner = null;

        try {
            try {
                $this->pdo()->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolationLevel);
                $this->pdo()->beginTransaction();

                // The first allocator locks the current tail (order 2).
                self::assertSame(3, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));

                $partner = $this->startWaitingAllocator($isolationLevel, 'scope-a', $waiterFlags);

                $connectionId = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
                self::assertStringStartsWith('CONNECTION_ID ', $connectionId);
                $this->awaitBlockedLockingRead(substr($connectionId, strlen('CONNECTION_ID ')));

                // The first allocator inserts the new tail and commits while the second waits.
                $insertCommitted = false;

                try {
                    $this->fixture->insertScoped('scope-a', 3);
                    $this->pdo()->commit();
                    $insertCommitted = true;
                } catch (PDOException $exception) {
                    if (($exception->errorInfo[1] ?? null) !== self::DEADLOCK_ERROR) {
                        throw $exception;
                    }
                }

                $report = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);

                if ($whileWaiterOpen !== null) {
                    $whileWaiterOpen($report);
                }

                return [$insertCommitted, $report];
            } finally {
                if ($this->pdo()->inTransaction()) {
                    $this->pdo()->rollBack();
                }
            }
        } finally {
            $partner?->stop();
        }
    }

    /**
     * Orders of the committed rows of scope-a, read on the other connection.
     *
     * @return list<int>
     */
    private function committedScopeAOrders(): array
    {
        $orders = array_values($this->otherReader->scopedOrdersById('scope-a'));
        sort($orders);

        return $orders;
    }

    /**
     * Tries to lock the scope-a row at the given order on the other connection
     * (one-second lock wait timeout) and returns how many rows it locked.
     */
    private function probeScopeARowLock(int $order): int
    {
        $statement = $this->otherPdo->prepare(
            'SELECT `id` FROM `' . $this->scopedTable . '`
              WHERE `scope_key` = \'scope-a\' AND `display_order` = :display_order FOR UPDATE',
        );
        self::assertNotFalse($statement);
        $statement->execute(['display_order' => $order]);

        return count($statement->fetchAll());
    }

    /**
     * Waits until the given connection is stuck inside a locking read, using
     * the other connection so the observation does not touch the caller's transaction.
     */
    private function awaitBlockedLockingRead(string $connectionId): void
    {
        $deadline = microtime(true) + 15;

        do {
            $rows = $this->otherPdo->query('SHOW FULL PROCESSLIST');
            self::assertNotFalse($rows);

            foreach ($rows->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = $row['Id'] ?? null;
                $command = $row['Command'] ?? null;
                $info = $row['Info'] ?? null;
                $time = $row['Time'] ?? null;

                if (
                    is_scalar($id) && (string) $id === $connectionId
                    && in_array($command, ['Query', 'Execute'], true)
                    && is_string($info) && str_contains($info, 'FOR UPDATE')
                    && is_numeric($time) && (int) $time >= 0
                ) {
                    return;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('The second allocator never blocked on the locking read.');
    }

    private function lockParentRow(PDO $pdo, int $parentId): void
    {
        $statement = $pdo->prepare(
            'SELECT `id` FROM `' . $this->globalTable . '` WHERE `id` = :id FOR UPDATE',
        );
        self::assertNotFalse($statement);
        $statement->execute(['id' => $parentId]);
        self::assertCount(1, $statement->fetchAll());
    }

    private function establishSnapshot(PDO $pdo): void
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM `' . $this->scopedTable . '`');
        self::assertNotFalse($statement);
        $statement->fetchColumn();
    }

    private function beginTransactionWithSnapshot(): void
    {
        $this->pdo()->beginTransaction();

        // Establish the REPEATABLE READ consistent-read snapshot before any
        // other connection commits.
        $this->countRows($this->scopedTable);
        $this->countRows($this->globalTable);
    }

    /**
     * @param non-empty-string $table
     */
    private function countRows(string $table): void
    {
        $statement = $this->pdo()->query('SELECT COUNT(*) FROM `' . $table . '`');
        self::assertNotFalse($statement);
        $statement->fetchColumn();
    }
}
