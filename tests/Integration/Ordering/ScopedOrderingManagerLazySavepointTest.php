<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\FaultInjectingPdo;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Tests\Support\Ordering\LazySavepointSchema;
use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/**
 * B-04: moveWithinScope() inside a caller-owned transaction undoes its own partial mutation
 * window with a lazy operation-local savepoint (taken after the scope lock, before the first
 * mutating statement, through the Transaction-owned SavepointScope — DEC-011 item 2) and never
 * commits, fully rolls back or retries the caller's transaction.
 *
 * Ported from legacy tests/Integration/Pdo/Ordering/ScopedOrderingManagerLazySavepointTest.php
 * (1f6a7aa, provenance gate recorded in the WU-8 pull request). The original
 * port adapted two cases to the application account's privileges. On MySQL
 * 8.4.13, the account has privileges on its own database, and binlog is on.
 *
 * - legacy's "false after the shifts" scenario (C04) and the matching client-injected variant
 *   (S5) both need the target write itself to report zero affected rows without throwing, which
 *   legacy achieved with a BEFORE UPDATE trigger that rewrites NEW back to OLD; CREATE TRIGGER
 *   needs SUPER or `log_bin_trust_function_creators=ON`. The application account
 *   received errors 1419/1458. ScopedOrderingManagerSettlementTest uses the
 *   disposable fixture admin for trigger setup and the application PDO for the move. The
 *   Unit wiring tests still cover the shared helper's statement sequence.
 * - legacy's lock-wait/deadlock (C07/C10) scenarios used a second AUDIT table that a trigger on
 *   the target update also touched, to land the failure strictly inside the savepoint window.
 *   Without a trigger, every row the shift/target update will touch is already exclusively
 *   locked by the scope lock before the savepoint begins, so a second connection cannot contend
 *   for them inside that window at all; this is not a weaker test of the same scenario, it is a
 *   different scenario. testGenuineDeadlockDuringTheScopeLockPropagatesUnchanged below proves the
 *   adjacent, still real invariant that *is* reachable: a genuine deadlock while acquiring the
 *   scope lock (before any savepoint exists) propagates the original PDOException unchanged,
 *   with no savepoint statement issued and no retry. C15 (a caller that swallows that same
 *   deadlock and calls again) is proven directly on top of it by
 *   testCallAfterSwallowedDeadlockEitherFailsClosedOrRunsAFreshOwnedTransaction (PR #17 review
 *   F17-02): the unit-level SavepointScopeTest (WU-6) characterizes the guard's own statement
 *   sequence in isolation, this proves its integration with Ordering after a real server-aborted
 *   transaction.
 */
final class ScopedOrderingManagerLazySavepointTest extends IntegrationTestCase
{
    private const INITIAL = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5];
    private const PARTNER_READ_TIMEOUT_SECONDS = 30.0;

    private PDO $pdo;
    private PDO $control;
    private LazySavepointSchema $schema;
    private ScopedOrderingManager $manager;

    /** @var list<PartnerProcess> */
    private array $partners = [];

    /** @var list<PDO> */
    private array $callerConnections = [];

    protected function setUp(): void
    {
        $this->control = $this->connect();
        $this->control->exec('SET SESSION lock_wait_timeout = 10');
        $this->schema = new LazySavepointSchema($this->control);
        $this->schema->initialize();
        $this->pdo = $this->connect();
        $this->manager = new ScopedOrderingManager();
    }

    protected function tearDown(): void
    {
        foreach ($this->partners as $partner) {
            $partner->stop();
        }
        $this->partners = [];

        foreach ([$this->pdo, ...$this->callerConnections] as $connection) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
        $this->callerConnections = [];

        $this->schema->drop();

        parent::tearDown();
    }

    // ---- C06: 1062 on the target write ----

    public function testDuplicateKeyOnTargetWriteUndoesShiftsAndRethrowsTheOriginal(): void
    {
        $this->beginCallerTransaction();

        try {
            $this->move($this->schema->unique, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        self::assertSame(self::INITIAL, $this->orders($this->pdo, $this->schema->unique));
        $this->assertCallerTransactionIntact();
        $this->pdo->commit();
        self::assertSame(self::INITIAL, $this->orders($this->control, $this->schema->unique));
    }

    // ---- genuine deadlock while acquiring the scope lock, before any savepoint ----

    /**
     * The caller's scope-lock SELECT ... FOR UPDATE scans the whole table in ascending (order,
     * id); a heavier partner holding the last row queues for the first row the caller already
     * holds, so the server picks the caller's lighter transaction as the deadlock victim before
     * any savepoint is taken.
     */
    public function testGenuineDeadlockDuringTheScopeLockPropagatesUnchanged(): void
    {
        $this->beginCallerTransaction();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');

        $firstRowStatement = $this->pdo->prepare(
            'SELECT `id` FROM `' . $this->schema->plain . '` WHERE `id` = 1 FOR UPDATE',
        );
        self::assertInstanceOf(PDOStatement::class, $firstRowStatement);
        $firstRowStatement->execute();
        $firstRowStatement->fetchAll();

        // $firstId=1 is already locked by the caller above; the partner locks $secondId=5
        // first (uncontended) and only reaches for row 1 after "go", creating the cycle.
        $partner = PartnerProcess::deadlockPartner($this->schema->plain, 1, 5);
        $this->partners[] = $partner;
        $ready = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
        self::assertStringStartsWith('READY ', $ready);
        $partner->writeLine('go');
        $this->awaitBlockedPartner(substr($ready, strlen('READY ')));

        $before = $this->counters();

        try {
            $this->move($this->schema->plain);
            self::fail('Expected the caller to be the deadlock victim.');
        } catch (PDOException $exception) {
            self::assertSame(1213, $exception->errorInfo[1] ?? null);
            self::assertSame('40001', $exception->errorInfo[0] ?? null);
        }

        // No savepoint was ever issued: the deadlock happened while acquiring the scope lock.
        $delta = $this->deltas($before);
        self::assertSame(0, $delta['savepoint']);
        self::assertSame(0, $delta['commit']);
        self::assertSame(0, $delta['rollback']);
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    // ---- C15: a caller that swallows a genuine deadlock and calls again ----

    /**
     * Legacy's testCallAfterSwallowedDeadlockFailsClosedBeforeAnyMutation (via an AFTER UPDATE
     * audit trigger this project cannot create, see the class docblock) swallowed a genuine 1213
     * and proved the second call failed closed before any mutation. This reuses the real
     * deadlock-at-scope-lock experiment of testGenuineDeadlockDuringTheScopeLockPropagatesUnchanged
     * and continues past the swallow instead of stopping there (PR #17 review F17-02). Observed
     * directly against mysql-8.4.13 and mariadb-11.8.9: PDO::inTransaction() reports true
     * immediately after the swallowed 1213 (the documented staleness) in both, so the second call
     * is treated as participating and SavepointScope::begin()'s dead-transaction guard fires,
     * throwing TransactionExecutionException before the scope lock or any mutation and leaving
     * PDO::inTransaction() correctly false afterward (a real round trip occurred). Both branches
     * are asserted explicitly rather than assumed, so a future engine/version where the driver
     * already reports the transaction ended is still covered, honestly, by the other branch.
     */
    public function testCallAfterSwallowedDeadlockEitherFailsClosedOrRunsAFreshOwnedTransaction(): void
    {
        $this->beginCallerTransaction();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');

        $firstRowStatement = $this->pdo->prepare(
            'SELECT `id` FROM `' . $this->schema->plain . '` WHERE `id` = 1 FOR UPDATE',
        );
        self::assertInstanceOf(PDOStatement::class, $firstRowStatement);
        $firstRowStatement->execute();
        $firstRowStatement->fetchAll();

        $partner = PartnerProcess::deadlockPartner($this->schema->plain, 1, 5);
        $this->partners[] = $partner;
        $ready = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
        self::assertStringStartsWith('READY ', $ready);
        $partner->writeLine('go');
        $this->awaitBlockedPartner(substr($ready, strlen('READY ')));

        try {
            $this->move($this->schema->plain);
            self::fail('Expected the caller to be the deadlock victim.');
        } catch (PDOException $exception) {
            self::assertSame(1213, $exception->errorInfo[1] ?? null);
        }

        // Forbidden pattern, deliberately reproduced here: the caller swallows the 1213 instead
        // of restarting the whole unit of work, and calls the manager again on the same connection.
        self::assertSame(['ACQUIRED'], $this->partnerReports());

        if ($this->pdo->inTransaction()) {
            // The documented staleness: the driver still reports the transaction active, so the
            // second call participates, and the dead-transaction guard must fail it closed before
            // any mutation — not after a partial shift, and not by silently starting an owned one.
            try {
                $this->move($this->schema->plain);
                self::fail('Expected the dead-transaction guard (TransactionExecutionException).');
            } catch (TransactionExecutionException) {
            }

            self::assertFalse($this->pdo->inTransaction(), 'The guard\'s own SAVEPOINT round trip refreshes the driver state.');
            self::assertSame(self::INITIAL, $this->orders($this->control, $this->schema->plain));

            return;
        }

        // The driver already reports the transaction ended: nothing is participating, so the
        // second call runs as its own fresh owned transaction and must succeed normally.
        self::assertTrue($this->move($this->schema->plain));
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $this->orders($this->control, $this->schema->plain));
    }

    // ---- C13: operation-local, an earlier successful move of the same transaction is kept ----

    public function testFailedSecondMoveKeepsTheFirstMoveOfTheSameTransaction(): void
    {
        $this->beginCallerTransaction();
        self::assertTrue($this->move($this->schema->unique, LazySavepointSchema::STAMP_OK));
        $afterFirst = $this->orders($this->pdo, $this->schema->unique);
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $afterFirst);

        try {
            $this->manager->moveWithinScope($this->pdo, $this->config($this->schema->unique), null, 1, 4, LazySavepointSchema::STAMP_OK);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        self::assertSame($afterFirst, $this->orders($this->pdo, $this->schema->unique));
        $this->assertCallerTransactionIntact();
        $this->pdo->commit();
        self::assertSame($afterFirst, $this->orders($this->control, $this->schema->unique));
    }

    // ---- C14: the caller's own savepoint stays usable ----

    public function testCallerSavepointSurvivesAFailedMove(): void
    {
        $this->beginCallerTransaction();
        $this->pdo->exec('SAVEPOINT caller_sp');

        try {
            $this->move($this->schema->unique, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException) {
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT caller_sp');
        self::assertSame(self::INITIAL, $this->orders($this->pdo, $this->schema->unique));
        $this->assertCallerTransactionIntact();
    }

    // ---- nested inside the package savepoint runner ----

    public function testMoveFailureInsideSavepointRunnerLeavesCallerWorkAndPriorMoveIntact(): void
    {
        $this->beginCallerTransaction();
        $runner = new PdoSavepointTransactionRunner($this->pdo);

        try {
            $runner->run(function (): bool {
                $this->move($this->schema->unique, LazySavepointSchema::STAMP_DUP);

                return true;
            });
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        self::assertSame(self::INITIAL, $this->orders($this->pdo, $this->schema->unique));
        $this->assertCallerTransactionIntact();
        self::assertTrue($runner->run(fn(): bool => $this->move($this->schema->unique, LazySavepointSchema::STAMP_OK)));
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $this->orders($this->pdo, $this->schema->unique));
    }

    // ---- C01-C03: savepoint statements only around a mutating window ----

    public function testSuccessfulMutatingMoveAddsExactlyOneSavepointAndOneRelease(): void
    {
        $this->beginCallerTransaction();
        $before = $this->counters();

        self::assertTrue($this->move($this->schema->plain));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 0, 'rollback' => 0, 'savepoint' => 1, 'release' => 1, 'rollback_to' => 0], $delta);
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $this->orders($this->pdo, $this->schema->plain));
        $this->assertCallerTransactionIntact();
    }

    public function testNoOpMoveIssuesNoSavepointStatement(): void
    {
        $this->beginCallerTransaction();
        $before = $this->counters();

        self::assertTrue($this->manager->moveWithinScope($this->pdo, $this->config($this->schema->plain), null, 5, 99, LazySavepointSchema::STAMP_OK));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 0, 'rollback' => 0, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        self::assertSame(self::INITIAL, $this->orders($this->pdo, $this->schema->plain));
    }

    public function testMissingRowIssuesNoSavepointStatement(): void
    {
        $this->beginCallerTransaction();
        $before = $this->counters();

        self::assertFalse($this->manager->moveWithinScope($this->pdo, $this->config($this->schema->plain), null, 99, 2, LazySavepointSchema::STAMP_OK));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 0, 'rollback' => 0, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        $this->assertCallerTransactionIntact();
    }

    // ---- O1-O3: the package-owned path is unchanged ----

    public function testOwnedPathSuccessUsesNoSavepointAndCommits(): void
    {
        $before = $this->counters();

        self::assertTrue($this->move($this->schema->plain));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 1, 'rollback' => 0, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $this->orders($this->control, $this->schema->plain));
    }

    public function testOwnedPathExceptionRollsBackTheWholeOwnedTransaction(): void
    {
        $before = $this->counters();

        try {
            $this->move($this->schema->unique, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        $delta = $this->deltas($before);
        self::assertSame(['commit' => 0, 'rollback' => 1, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(self::INITIAL, $this->orders($this->control, $this->schema->unique));
    }

    public function testOwnedPathMissingRowRollsBackTheWholeOwnedTransaction(): void
    {
        $before = $this->counters();

        self::assertFalse($this->manager->moveWithinScope($this->pdo, $this->config($this->schema->plain), null, 99, 2, LazySavepointSchema::STAMP_OK));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 0, 'rollback' => 1, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(self::INITIAL, $this->orders($this->control, $this->schema->plain));
    }

    // ---- the scope lock outlives the failed move and its local rollback (why the savepoint is lazy) ----

    /**
     * The caller transaction has done no write before the call: only then does InnoDB release the row locks taken after
     * a savepoint on ROLLBACK TO SAVEPOINT, so this is the case in which an eager savepoint (taken before the scope
     * lock) would free the scope and a later conflicting writer could slip in. The lazy placement must not.
     */
    public function testScopeLockSurvivesFailedMoveAndLocalRollbackUntilTheCallerEndsItsTransaction(): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->move($this->schema->unique, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        // The move was undone locally; the caller's transaction is still open.
        self::assertSame(self::INITIAL, $this->orders($this->pdo, $this->schema->unique));
        self::assertTrue($this->pdo->inTransaction());

        $other = $this->connect();
        $this->callerConnections[] = $other;
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        // Every row of the scope is still locked: a savepoint taken before the scope lock would have released it.
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->assertBlockedByScopeLock($other, 'UPDATE `' . $this->schema->unique . '` SET `label` = \'other\' WHERE `id` = ' . $id);
        }

        $this->assertBlockedByScopeLock(
            $other,
            'INSERT INTO `' . $this->schema->unique . '` (`display_order`, `updated_at`, `label`) VALUES (6, \'2026-03-01 00:00:00\', \'other\')',
        );

        // Once the caller ends its transaction the scope is free again.
        $this->pdo->rollBack();
        self::assertSame(1, $other->exec('UPDATE `' . $this->schema->unique . '` SET `label` = \'other\' WHERE `id` = 3'));
    }

    // ---- S1-S7: savepoint-control failures on a real connection ----

    public function testS1SavepointReturningFalseFailsClosedBeforeAnyMutation(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^SAVEPOINT /', 'false');

        try {
            $this->moveOn($faulty, $this->schema->plain);
            self::fail('Expected TransactionExecutionException.');
        } catch (Throwable $throwable) {
            self::assertStringContainsString('TransactionExecutionException', $throwable::class);
        }

        self::assertSame(self::INITIAL, $this->orders($faulty, $this->schema->plain));
        self::assertTrue($faulty->inTransaction());
    }

    public function testS2SavepointThrowingPropagatesBeforeAnyMutation(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^SAVEPOINT /', 'throw');

        try {
            $this->moveOn($faulty, $this->schema->plain);
            self::fail('Expected the injected PDOException.');
        } catch (PDOException $exception) {
            self::assertSame('Injected control statement failure.', $exception->getMessage());
        }

        self::assertSame(self::INITIAL, $this->orders($faulty, $this->schema->plain));
    }

    public function testS3OriginalExceptionWinsOverAFalseRollbackTo(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^ROLLBACK TO SAVEPOINT/', 'false');

        try {
            $this->moveOn($faulty, $this->schema->unique, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        // Residual (documented): the failed undo leaves the partial shifts for the caller to roll back.
        self::assertTrue($faulty->inTransaction());
        $faulty->rollBack();
        self::assertSame(self::INITIAL, $this->orders($this->control, $this->schema->unique));
    }

    public function testS4OriginalExceptionWinsOverAThrowingRollbackTo(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failPrepare('/SET `display_order` = :new_order_case/');
        $faulty->failExec('/^ROLLBACK TO SAVEPOINT/', 'throw');

        try {
            $this->moveOn($faulty, $this->schema->plain);
            self::fail('Expected the injected RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected prepare failure.', $exception->getMessage());
        }

        $faulty->rollBack();
    }

    // ScopedOrderingManagerSettlementTest covers S5 through the raw public
    // manager. A fixture trigger makes the real target UPDATE affect zero rows.

    public function testS6ReleaseReturningFalseUndoesTheMoveAndFails(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^RELEASE SAVEPOINT/', 'false');

        try {
            $this->moveOn($faulty, $this->schema->plain);
            self::fail('Expected TransactionExecutionException.');
        } catch (Throwable $throwable) {
            self::assertStringContainsString('TransactionExecutionException', $throwable::class);
        }

        self::assertSame(self::INITIAL, $this->orders($faulty, $this->schema->plain));
        self::assertTrue($faulty->inTransaction());
    }

    public function testS7ReleaseThrowingUndoesTheMoveAndRethrowsTheReleaseFailure(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^RELEASE SAVEPOINT/', 'throw');

        try {
            $this->moveOn($faulty, $this->schema->plain);
            self::fail('Expected the injected PDOException.');
        } catch (PDOException $exception) {
            self::assertSame('Injected control statement failure.', $exception->getMessage());
        }

        self::assertSame(self::INITIAL, $this->orders($faulty, $this->schema->plain));
    }

    public function testSavepointNameFollowsTheMaatifyPersistenceContract(): void
    {
        $faulty = $this->faultyCallerTransaction();

        self::assertTrue($this->moveOn($faulty, $this->schema->plain));

        $statements = $faulty->savepointStatements();
        self::assertCount(2, $statements);
        self::assertSame(1, preg_match('/^SAVEPOINT (maatify_persistence_sp_[0-9a-f]{32})$/', $statements[0], $matches));
        self::assertSame('RELEASE SAVEPOINT ' . ($matches[1] ?? ''), $statements[1]);
        $faulty->rollBack();
    }

    // ---- helpers ----

    /** @param non-empty-string $table */
    private function config(string $table): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: $table, updatedAtColumn: 'updated_at');
    }

    /** @param non-empty-string $table */
    private function move(string $table, string $stamp = LazySavepointSchema::STAMP_OK): bool
    {
        return $this->moveOn($this->pdo, $table, $stamp);
    }

    /** @param non-empty-string $table */
    private function moveOn(PDO $pdo, string $table, string $stamp = LazySavepointSchema::STAMP_OK): bool
    {
        return $this->manager->moveWithinScope(
            $pdo,
            $this->config($table),
            null,
            LazySavepointSchema::MOVE_ID,
            LazySavepointSchema::MOVE_TO,
            $stamp,
        );
    }

    private function beginCallerTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('INSERT INTO `' . $this->schema->marker . "` (`note`) VALUES ('caller-earlier-work')");
    }

    private function faultyCallerTransaction(): FaultInjectingPdo
    {
        $faulty = $this->connectFaultInjecting();
        $this->callerConnections[] = $faulty;
        $faulty->beginTransaction();
        $faulty->exec('INSERT INTO `' . $this->schema->marker . "` (`note`) VALUES ('caller-earlier-work')");

        return $faulty;
    }

    private function connectFaultInjecting(): FaultInjectingPdo
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ];
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');

        return new FaultInjectingPdo($dsn, self::setting('USER'), self::setting('PASSWORD'), $options);
    }

    private function assertBlockedByScopeLock(PDO $other, string $sql): void
    {
        try {
            $other->exec($sql);
            self::fail('The scope lock was released too early: ' . $sql);
        } catch (PDOException $exception) {
            self::assertSame(1205, $exception->errorInfo[1] ?? null, $exception->getMessage());
        }
    }

    private function assertCallerTransactionIntact(): void
    {
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(1, $this->markerCount($this->pdo));
    }

    private function markerCount(?PDO $pdo = null): int
    {
        $statement = ($pdo ?? $this->control)->query('SELECT COUNT(*) FROM `' . $this->schema->marker . '`');
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /** @return array<int, int> */
    private function orders(PDO $pdo, string $table): array
    {
        $statement = $pdo->query('SELECT `id`, `display_order` FROM `' . $table . '` ORDER BY `id`');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $orders = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            $order = is_array($row) ? ($row['display_order'] ?? null) : null;

            if (!is_numeric($id) || !is_numeric($order)) {
                throw new AssertionFailedError('Unexpected ordering row.');
            }

            $orders[(int) $id] = (int) $order;
        }

        return $orders;
    }

    /** @return array{commit: int, rollback: int, savepoint: int, release: int, rollback_to: int} */
    private function counters(): array
    {
        return [
            'commit' => $this->statusCounter('Com_commit'),
            'rollback' => $this->statusCounter('Com_rollback'),
            'savepoint' => $this->statusCounter('Com_savepoint'),
            'release' => $this->statusCounter('Com_release_savepoint'),
            'rollback_to' => $this->statusCounter('Com_rollback_to_savepoint'),
        ];
    }

    /** @param non-empty-string $variable */
    private function statusCounter(string $variable): int
    {
        $statement = $this->pdo->query("SHOW SESSION STATUS LIKE '" . $variable . "'");
        self::assertInstanceOf(PDOStatement::class, $statement);
        $value = $statement->fetchColumn(1);

        if (!is_numeric($value)) {
            throw new AssertionFailedError('Unexpected status counter ' . $variable . '.');
        }

        return (int) $value;
    }

    /**
     * @param array{commit: int, rollback: int, savepoint: int, release: int, rollback_to: int} $before
     *
     * @return array{commit: int, rollback: int, savepoint: int, release: int, rollback_to: int}
     */
    private function deltas(array $before): array
    {
        $after = $this->counters();

        return [
            'commit' => $after['commit'] - $before['commit'],
            'rollback' => $after['rollback'] - $before['rollback'],
            'savepoint' => $after['savepoint'] - $before['savepoint'],
            'release' => $after['release'] - $before['release'],
            'rollback_to' => $after['rollback_to'] - $before['rollback_to'],
        ];
    }

    /** @return list<string> */
    private function partnerReports(): array
    {
        return array_map(
            static fn(PartnerProcess $partner): string => $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS),
            $this->partners,
        );
    }

    private function awaitBlockedPartner(string $connectionId): void
    {
        $deadline = microtime(true) + 15;

        do {
            $rows = $this->control->query('SHOW FULL PROCESSLIST');
            self::assertInstanceOf(PDOStatement::class, $rows);

            foreach ($rows->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $info = $row['Info'] ?? null;
                $time = $row['Time'] ?? null;
                $id = $row['Id'] ?? null;

                if (
                    is_scalar($id) && (string) $id === $connectionId
                    && is_string($info) && str_contains($info, 'FOR UPDATE')
                    && is_numeric($time) && (int) $time >= 0
                ) {
                    return;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new AssertionFailedError('The deadlock partner never blocked on its locking read.');
    }
}
