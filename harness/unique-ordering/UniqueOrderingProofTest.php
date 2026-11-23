<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Harness\UniqueOrdering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;

/** Opt-in real InnoDB observations. Prototype statements are test fixtures. */
final class UniqueOrderingProofTest extends IntegrationTestCase
{
    /** @var list<non-empty-string> */
    private array $proofTables = [];

    /** @return iterable<string, array{string, bool}> */
    public static function movements(): iterable
    {
        foreach (['global', 'scoped', 'active-null'] as $layout) {
            yield "$layout up" => [$layout, true];
            yield "$layout down" => [$layout, false];
        }
    }

    #[DataProvider('movements')]
    public function testCurrentShiftFailsAndRawHostCanCommitItsEarlierWork(string $layout, bool $up): void
    {
        $host = $this->connect();
        $observer = $this->connect();
        $table = $this->positions($host, $layout);
        $marker = $this->createTable($host, 'unique_marker');
        $this->seed($host, $table, $layout);
        $manager = new ScopedOrderingManager();
        $config = self::config($table, $layout);
        $scope = $layout === 'scoped' ? 7 : null;
        $id = $up ? 4 : 1;
        $destination = $up ? 1 : 4;
        $initial = $this->rows($observer, $table);
        $this->duplicate(self::failureOf(fn() => $manager->moveWithinScope($host, $config, $scope, $id, $destination)));
        self::assertFalse($host->inTransaction());
        self::assertSame($initial, $this->rows($observer, $table));

        $host->beginTransaction();
        try {
            $host->exec("INSERT INTO `$marker` VALUES (1, 17)");
            self::assertTrue($manager->moveWithinScope($host, $config, $scope, $id, $id));
            self::assertFalse($manager->moveWithinScope($host, $config, $scope, 999, 1));
            $this->duplicate(self::failureOf(fn() => $manager->moveWithinScope($host, $config, $scope, $id, $destination)));
            self::assertTrue($host->inTransaction());
            self::assertSame($initial, $this->rows($host, $table));
            self::assertSame('17', $this->scalar($host, "SELECT v FROM `$marker`"));
            self::assertSame('0', $this->scalar($observer, "SELECT COUNT(*) FROM `$marker`"));
            $host->commit();
        } finally {
            self::endIfActive($host);
        }
        self::assertSame('17', $this->scalar($observer, "SELECT v FROM `$marker`"));
        self::assertSame($initial, $this->rows($observer, $table));
        self::evidence('baseline-collision', ['layout' => $layout, 'direction' => $up ? 'up' : 'down', 'code' => '23000/1062']);
    }

    #[DataProvider('movements')]
    public function testOnlyAFreeSlotAndCorrectShiftDirectionSucceed(string $layout, bool $up): void
    {
        $pdo = $this->connect();
        $table = $this->positions($pdo, $layout);
        $this->seed($pdo, $table, $layout);
        $scope = self::scope($layout);
        $id = $up ? 4 : 1;
        $change = $up ? '+ 1' : '- 1';
        $bounds = $up ? 'display_order BETWEEN 1 AND 3' : 'display_order BETWEEN 2 AND 4';
        $right = $up ? 'DESC' : 'ASC';
        $wrong = $up ? 'ASC' : 'DESC';
        $shift = "UPDATE `$table` SET display_order = display_order $change WHERE $scope AND $bounds ORDER BY display_order";
        $this->duplicate(self::failureOf(fn() => $pdo->exec("$shift $right")));
        self::assertSame([1, 2, 3, 4], $this->orders($pdo, $table, $scope));

        $pdo->beginTransaction();
        try {
            $lock = $pdo->query("SELECT id FROM `$table` WHERE $scope ORDER BY display_order FOR UPDATE");
            self::assertInstanceOf(PDOStatement::class, $lock);
            self::assertCount(4, $lock->fetchAll());
            $lock->closeCursor();
            $pdo->exec('SAVEPOINT proof_move');
            self::assertSame(1, $pdo->exec("UPDATE `$table` SET display_order = 5 WHERE id = $id"));
            $parked = $up ? [1, 2, 3, 5] : [5, 2, 3, 4];
            self::assertSame($parked, $this->orders($pdo, $table, $scope));
            $this->duplicate(self::failureOf(fn() => $pdo->exec("$shift $wrong")));
            self::assertSame($parked, $this->orders($pdo, $table, $scope));
            self::assertSame(3, $pdo->exec("$shift $right"));
            self::assertSame($up ? [2, 3, 4, 5] : [5, 1, 2, 3], $this->orders($pdo, $table, $scope));
            self::assertSame(1, $pdo->exec("UPDATE `$table` SET display_order = " . ($up ? 1 : 4) . " WHERE id = $id"));
            $expected = $up ? [2, 3, 4, 1] : [4, 1, 2, 3];
            self::assertSame($expected, $this->orders($pdo, $table, $scope));
            $pdo->exec('RELEASE SAVEPOINT proof_move');
            $pdo->commit();
        } finally {
            self::endIfActive($pdo);
        }
        $observer = $this->connect();
        self::assertSame($expected, $this->orders($observer, $table, $scope));
        if ($layout === 'active-null') {
            self::assertSame([2], $this->orders($pdo, $table, 'id = 10'));
        }
        if ($layout !== 'global') {
            self::assertSame([1], $this->orders($pdo, $table, 'id = 11'));
        }
        self::evidence('ordered-slot', ['layout' => $layout, 'direction' => $right, 'parked' => $parked, 'final' => $expected]);
    }

    public function testNullAndSoftDeleteIndexSemantics(): void
    {
        $pdo = $this->connect();
        $raw = $this->positions($pdo, 'scoped');
        $pdo->exec("INSERT INTO `$raw` (id, scope_key, display_order) VALUES (1, NULL, 1), (2, NULL, 1), (3, 7, 1), (4, 8, 1)");
        self::assertSame([1, 1], $this->orders($pdo, $raw, 'scope_key IS NULL'));
        $this->duplicate(self::failureOf(fn() => $pdo->exec("INSERT INTO `$raw` VALUES (DEFAULT, 7, 1, '2022-01-01', '')")));

        $fixed = $this->positions($pdo, 'active-null');
        $pdo->exec("INSERT INTO `$fixed` (id, scope_key, display_order, deleted_at) VALUES (1, NULL, 1, '2022-01-01'), (2, NULL, 1, '2022-01-01'), (3, NULL, 1, NULL), (4, 7, 1, NULL)");
        $this->duplicate(self::failureOf(fn() => $pdo->exec("INSERT INTO `$fixed` (scope_key, display_order) VALUES (NULL, 1)")));
        $this->duplicate(self::failureOf(fn() => $pdo->exec("UPDATE `$fixed` SET deleted_at = NULL WHERE id = 1")));
        self::assertSame([1, 1], $this->orders($pdo, $fixed, 'scope_key IS NULL AND deleted_at IS NOT NULL'));
        self::assertSame([1], $this->orders($pdo, $fixed, 'scope_key IS NULL AND deleted_at IS NULL'));
        self::evidence('index-semantics', ['raw_null_duplicates' => 2, 'all_row_deleted_collision' => 1062, 'normalized_active_null_collision' => 1062, 'restore_collision' => 1062]);
    }

    public function testDeletedRowOccupiesAnApparentlyFreeStagingSlot(): void
    {
        $pdo = $this->connect();
        $table = $this->positions($pdo, 'scoped');
        $this->seed($pdo, $table, 'scoped');
        $pdo->exec("INSERT INTO `$table` (id, scope_key, display_order, deleted_at) VALUES (20, 7, 5, '2022-01-01')");
        $active = 'scope_key = 7 AND deleted_at IS NULL';
        $next = (new ScopedOrderingManager())->getNextPosition($pdo, self::config($table, 'scoped'), 7);
        self::assertSame(5, $next);
        $this->duplicate(self::failureOf(fn() => $pdo->exec("UPDATE `$table` SET display_order = $next WHERE id = 4")));
        self::assertSame([1, 2, 3, 4], $this->orders($pdo, $table, $active));
        self::evidence('deleted-slot', ['active_next' => $next, 'parking_error' => '23000/1062']);
    }

    /** @return iterable<string, array{int}> */
    public static function returnedErrors(): iterable
    {
        yield 'silent' => [PDO::ERRMODE_SILENT];
        yield 'warning' => [PDO::ERRMODE_WARNING];
    }

    #[DataProvider('returnedErrors')]
    public function testCurrentUniqueCollisionRespectsReturnValueErrorMode(int $mode): void
    {
        $pdo = $this->connect();
        $table = $this->positions($pdo, 'global');
        $this->seed($pdo, $table, 'global');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;
            return $level === E_WARNING && str_contains($message, '1062');
        });
        try {
            (new ScopedOrderingManager())->moveWithinScope($pdo, self::config($table, 'global'), null, 4, 1);
            self::fail('The shift must report a failed statement.');
        } catch (OrderingExecutionException $error) {
            self::assertStringContainsString('shiftOrdersUp', $error->getMessage());
            self::assertSame($mode, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        } finally {
            restore_error_handler();
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        self::assertSame($mode === PDO::ERRMODE_WARNING, $warning !== null);
        self::assertSame([1, 2, 3, 4], $this->orders($pdo, $table, '1 = 1'));
        self::assertFalse($pdo->inTransaction());
    }

    public function testProtectedUniqueFailureFencesCallerCommandsWithoutClaimingRollback(): void
    {
        $observer = $this->connect();
        $table = $this->positions($observer, 'global');
        $marker = $this->createTable($observer, 'protected_marker');
        $this->seed($observer, $table, 'global');
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');
        $delegated = new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $thread = (int) $this->scalar($delegated, 'SELECT CONNECTION_ID()');
        $checkout = PdoProtectedTransactionCheckout::attachExclusive(
            $delegated,
            new ProtectedTransactionCommandCatalog(['mark' => new ProtectedTransactionCommandPlan("INSERT INTO `$marker` VALUES (1, 17)", [])]),
        );
        unset($delegated);
        try {
            $checkout->begin();
            self::assertSame(1, $checkout->runRegisteredCommand('mark'));
            $manager = new ProtectedScopedOrderingManager();
            $this->duplicate(self::failureOf(fn() => $manager->moveWithinScope($checkout, self::config($table, 'global'), null, 4, 1)));
            $settlement = $checkout->settlement();
            self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $settlement?->disposition);
            $refused = 0;
            foreach ([$checkout->commit(...), $checkout->returnForHost(...), static fn() => $checkout->runRegisteredCommand('mark')] as $call) {
                try {
                    $call();
                    self::fail('A fenced checkout must reject further use.');
                } catch (ProtectedTransactionException) {
                    $refused++;
                }
            }
            self::assertSame(3, $refused);
            self::assertSame($settlement, $checkout->settlement());
            self::assertSame('0', $this->scalar($observer, "SELECT COUNT(*) FROM `$marker`"));
        } finally {
            $checkout->discardForHost();
            // A discard need not terminate the server session immediately.
            // Only the disposable fixture connection may be stopped here.
            $stillConnected = $this->scalar($observer, "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $thread") === '1';
            if ($stillConnected) {
                try {
                    $observer->exec("KILL CONNECTION $thread");
                } catch (PDOException $error) {
                    self::assertSame(1094, self::driverCode($error));
                }
            }
        }
        self::assertSame([1, 2, 3, 4], $this->orders($observer, $table, '1 = 1'));
        self::assertSame('0', $this->scalar($observer, "SELECT COUNT(*) FROM `$marker`"));
        self::evidence('protected-collision', ['code' => '23000/1062', 'disposition' => 'DISCARD_REQUIRED', 'session_present_after_discard' => $stillConnected]);
    }

    public function testEmptyReadRaceAndParentLockFirstInsert(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $table = $this->positions($a, 'scoped');
        $parent = $this->createTable($a, 'scope_anchor');
        $a->exec("INSERT INTO `$parent` VALUES (7, 0), (8, 0)");
        $this->readCommitted($a, $b);
        $config = self::config($table, 'scoped');
        $manager = new ScopedOrderingManager();

        $a->beginTransaction();
        $b->beginTransaction();
        try {
            self::assertSame(1, $manager->getNextPositionForUpdate($a, $config, 7));
            self::assertSame(1, $manager->getNextPositionForUpdate($b, $config, 7));
            $a->exec("INSERT INTO `$table` (id, scope_key, display_order) VALUES (1, 7, 1)");
            $a->commit();
            $this->duplicate(self::failureOf(fn() => $b->exec("INSERT INTO `$table` (id, scope_key, display_order) VALUES (2, 7, 1)")));
        } finally {
            self::endIfActive($a);
            self::endIfActive($b);
        }

        $a->beginTransaction();
        $b->beginTransaction();
        try {
            $lock = "SELECT id FROM `$parent` WHERE id = 8 FOR UPDATE";
            self::assertSame('8', $this->scalar($a, $lock));
            self::assertSame(1, $manager->getNextPositionForUpdate($a, $config, 8));
            $wait = self::failureOf(fn() => $this->scalar($b, $lock));
            self::assertSame('HY000', self::sqlState($wait));
            self::assertSame(1205, self::driverCode($wait));
            self::assertTrue($b->inTransaction());
            $b->rollBack();
            $a->exec("INSERT INTO `$table` (id, scope_key, display_order) VALUES (3, 8, 1)");
            self::assertSame([], $this->orders($b, $table, 'scope_key = 8'));
            $a->commit();
            $b->beginTransaction();
            self::assertSame('8', $this->scalar($b, $lock));
            self::assertSame(2, $manager->getNextPositionForUpdate($b, $config, 8));
            $b->exec("INSERT INTO `$table` (id, scope_key, display_order) VALUES (4, 8, 2)");
            $b->commit();
        } finally {
            self::endIfActive($a);
            self::endIfActive($b);
        }
        self::assertSame([1], $this->orders($a, $table, 'scope_key = 7'));
        self::assertSame([1, 2], $this->orders($a, $table, 'scope_key = 8'));
        self::evidence('first-insert', ['empty_reads' => [1, 1], 'collision' => 1062, 'anchor_wait' => 1205, 'anchored_positions' => [1, 2]]);
    }

    /** @return iterable<string, array{bool}> */
    public static function outerDecisions(): iterable
    {
        yield 'commit' => [true];
        yield 'rollback' => [false];
    }

    #[DataProvider('outerDecisions')]
    public function testCompetingMoveAndAppendWaitForTheSameAnchor(bool $commit): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $table = $this->positions($a, 'scoped');
        $marker = $this->createTable($a, 'writer_anchor');
        $a->exec("INSERT INTO `$marker` VALUES (7, 0)");
        $this->seed($a, $table, 'scoped');
        $this->readCommitted($a, $b);
        $lock = "SELECT id FROM `$marker` WHERE id = 7 FOR UPDATE";
        $predicate = 'scope_key = 7';
        $a->beginTransaction();
        $b->beginTransaction();
        try {
            self::assertSame('7', $this->scalar($a, $lock));
            $a->exec("UPDATE `$marker` SET v = 1 WHERE id = 7");
            $a->exec('SAVEPOINT failed_shift');
            $a->exec("UPDATE `$table` SET display_order = 5 WHERE id = 4");
            $a->exec("UPDATE `$table` SET display_order = display_order + 1 WHERE $predicate AND display_order BETWEEN 1 AND 3 ORDER BY display_order DESC");
            $this->duplicate(self::failureOf(fn() => $a->exec("UPDATE `$table` SET display_order = 2 WHERE id = 4")));
            $a->exec('ROLLBACK TO SAVEPOINT failed_shift');
            $a->exec('RELEASE SAVEPOINT failed_shift');
            self::assertSame([1, 2, 3, 4], $this->orders($a, $table, $predicate));
            self::assertSame('1', $this->scalar($a, "SELECT v FROM `$marker` WHERE id = 7"));

            $a->exec('SAVEPOINT good_shift');
            $a->exec("UPDATE `$table` SET display_order = 5 WHERE id = 4");
            self::assertSame([1, 2, 3, 4], $this->orders($b, $table, $predicate));
            $wait = self::failureOf(fn() => $this->scalar($b, $lock));
            self::assertSame('HY000', self::sqlState($wait));
            self::assertSame(1205, self::driverCode($wait));
            $b->rollBack();
            $a->exec("UPDATE `$table` SET display_order = display_order + 1 WHERE $predicate AND display_order BETWEEN 1 AND 3 ORDER BY display_order DESC");
            $a->exec("UPDATE `$table` SET display_order = 1 WHERE id = 4");
            $a->exec('RELEASE SAVEPOINT good_shift');
            $commit ? $a->commit() : $a->rollBack();

            $b->beginTransaction();
            self::assertSame('7', $this->scalar($b, $lock));
            $current = (int) $this->scalar($b, "SELECT display_order FROM `$table` WHERE $predicate AND id = 3 FOR UPDATE");
            self::assertSame($commit ? 4 : 3, $current);
            self::assertSame($commit ? [2, 3, 4, 1] : [1, 2, 3, 4], $this->orders($b, $table, $predicate));
            self::assertSame($commit ? '1' : '0', $this->scalar($b, "SELECT v FROM `$marker` WHERE id = 7"));
            $b->exec("UPDATE `$table` SET display_order = 5 WHERE id = 3");
            $b->exec("UPDATE `$table` SET display_order = display_order + 1 WHERE $predicate AND display_order >= 1 AND display_order < $current ORDER BY display_order DESC");
            $b->exec("UPDATE `$table` SET display_order = 1 WHERE id = 3");
            $next = (new ScopedOrderingManager())->getNextPositionForUpdate($b, self::config($table, 'scoped'), 7);
            self::assertSame(5, $next);
            $b->exec("INSERT INTO `$table` (id, scope_key, display_order) VALUES (20, 7, $next)");
            $b->commit();
        } finally {
            self::endIfActive($a);
            self::endIfActive($b);
        }
        $final = $commit ? [3, 4, 1, 2, 5] : [2, 3, 1, 4, 5];
        self::assertSame($final, $this->orders($a, $table, $predicate));
        self::evidence('competing-writers', [
            'outer_commit' => $commit,
            'failed_step' => 1062,
            'anchor_wait' => 1205,
            'move_position_read' => $current,
            'append_position' => $next,
            'final' => $final,
        ]);
    }

    public function testSingleRowPrependAndSlotOverflowBoundary(): void
    {
        $pdo = $this->connect();
        $table = $this->positions($pdo, 'global');
        $manager = new ScopedOrderingManager();
        $config = self::config($table, 'global');
        self::assertSame(1, $manager->getNextPosition($pdo, $config, null));
        $pdo->exec("INSERT INTO `$table` (id, display_order) VALUES (1, 1)");
        self::assertTrue($manager->moveWithinScope($pdo, $config, null, 1, 63));
        $pdo->beginTransaction();
        try {
            self::assertSame('1', $this->scalar($pdo, "SELECT id FROM `$table` WHERE id = 1 FOR UPDATE"));
            self::assertSame(1, $pdo->exec("UPDATE `$table` SET display_order = display_order + 1 ORDER BY display_order DESC"));
            $pdo->exec("INSERT INTO `$table` (id, display_order) VALUES (2, 1)");
            $pdo->commit();
        } finally {
            self::endIfActive($pdo);
        }
        self::assertSame([2, 1], $this->orders($pdo, $table, '1 = 1'));

        $limit = PHP_INT_MAX;
        $pdo->exec("UPDATE `$table` SET display_order = $limit WHERE id = 1");
        foreach ([$manager->getNextPosition(...), $manager->getNextPositionForUpdate(...)] as $next) {
            $pdo->beginTransaction();
            try {
                $next($pdo, $config, null);
                self::fail('There is no upper slot representable by a PHP integer.');
            } catch (OrderingExecutionException $error) {
                self::assertStringContainsString('outside the PHP integer range', $error->getMessage());
            } finally {
                $pdo->rollBack();
            }
        }
        self::assertSame([$limit, 1], $this->orders($pdo, $table, '1 = 1'));
        $full = $this->positions($pdo, 'bounded');
        $this->seed($pdo, $full, 'global');
        $error = self::failureOf(fn() => $pdo->exec("UPDATE `$full` SET display_order = 5 WHERE id = 4"));
        $code = self::engine() === 'mariadb' ? 4025 : 3819;
        self::assertSame($code, self::driverCode($error));
        self::assertSame([1, 2, 3, 4], $this->orders($pdo, $full, '1 = 1'));
        self::evidence('bounds', ['prepend' => [2, 1], 'max' => $limit, 'bounded_check_error' => $code]);
    }

    public function testUniqueScopeStaleSnapshotMatchesEnginePolicy(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $table = $this->positions($a, 'scoped');
        $marker = $this->createTable($a, 'snapshot_marker');
        $this->seed($a, $table, 'scoped');
        $maria = self::engine() === 'mariadb';
        $a->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if ($maria) {
            $a->exec('SET SESSION innodb_snapshot_isolation = ON');
        }
        $a->beginTransaction();
        try {
            self::assertSame([1, 2, 3, 4], $this->orders($a, $table, 'scope_key = 7'));
            $a->exec("INSERT INTO `$marker` VALUES (1, 17)");
            $b->exec("UPDATE `$table` SET display_order = 5 WHERE id = 4");
            $allocate = fn() => (new ScopedOrderingManager())->getNextPositionForUpdate($a, self::config($table, 'scoped'), 7);
            if ($maria) {
                $error = self::failureOf($allocate);
                self::assertSame('HY000', self::sqlState($error));
                self::assertSame(1020, self::driverCode($error));
                self::assertSame('0', $this->scalar($a, 'SELECT @@in_transaction'));
                self::assertSame('0', $this->scalar($a, "SELECT COUNT(*) FROM `$marker`"));
            } else {
                self::assertSame(6, $allocate());
                self::assertTrue($a->inTransaction());
                self::assertSame('17', $this->scalar($a, "SELECT v FROM `$marker`"));
            }
        } finally {
            self::endIfActive($a);
        }
        self::assertSame('0', $this->scalar($b, "SELECT COUNT(*) FROM `$marker`"));
        self::evidence('stale-snapshot', $maria ? ['code' => 'HY000/1020', 'marker_rolled_back' => true] : ['next' => 6, 'marker_held' => true]);
    }

    public function testRealDeadlockOnUniqueScopeEndsRawHostTransaction(): void
    {
        $host = $this->connect();
        $table = $this->positions($host, 'scoped');
        $marker = $this->createTable($host, 'deadlock_marker');
        $this->seed($host, $table, 'scoped');
        $this->readCommitted($host);
        $host->exec('SET SESSION innodb_lock_wait_timeout = 20');
        $host->beginTransaction();
        $partner = null;
        try {
            $host->exec("INSERT INTO `$marker` VALUES (1, 17)");
            self::assertSame('1', $this->scalar($host, "SELECT id FROM `$table` WHERE id = 1 FOR UPDATE"));
            $partner = PartnerProcess::deadlockPartner($table, 1, 4);
            self::assertStringStartsWith('READY ', $partner->readLine(15.0));
            $partner->writeLine('go');
            $error = self::failureOf(fn() => (new ScopedOrderingManager())->moveWithinScope($host, self::config($table, 'scoped'), 7, 4, 1));
            self::assertSame('40001', self::sqlState($error));
            self::assertSame(1213, self::driverCode($error));
            self::assertSame('ACQUIRED', $partner->readLine(15.0));
            self::assertSame('0', $this->scalar($host, "SELECT COUNT(*) FROM `$marker`"));
        } finally {
            $partner?->stop();
            self::endIfActive($host);
        }
        $observer = $this->connect();
        self::assertSame([1, 2, 3, 4], $this->orders($observer, $table, 'scope_key = 7'));
        self::assertSame('0', $this->scalar($observer, "SELECT COUNT(*) FROM `$marker`"));
        self::evidence('deadlock', ['code' => '40001/1213', 'partner_acquired' => true, 'marker_absent' => true]);
    }

    public function testGapsPermitSingleWritesUntilHeadroomIsConsumed(): void
    {
        $pdo = $this->connect();
        $table = $this->positions($pdo, 'global');
        $pdo->exec("INSERT INTO `$table` (id, display_order) VALUES (1, 10), (2, 20), (3, 30)");
        $pdo->beginTransaction();
        try {
            self::assertSame('10', $this->scalar($pdo, "SELECT display_order FROM `$table` WHERE id = 1 FOR UPDATE"));
            self::assertSame('20', $this->scalar($pdo, "SELECT display_order FROM `$table` WHERE id = 2 FOR UPDATE"));
            self::assertSame(1, $pdo->exec("UPDATE `$table` SET display_order = 15 WHERE id = 3"));
            self::assertSame([10, 20, 15], $this->orders($pdo, $table, '1 = 1'));
            for ($position = 11; $position < 15; $position++) {
                $pdo->exec("INSERT INTO `$table` (display_order) VALUES ($position)");
            }
            $this->duplicate(self::failureOf(fn() => $pdo->exec("INSERT INTO `$table` (display_order) VALUES (10)")));
            $this->duplicate(self::failureOf(fn() => $pdo->exec("INSERT INTO `$table` (display_order) VALUES (11)")));
            $pdo->rollBack();
        } finally {
            self::endIfActive($pdo);
        }
        self::assertSame([10, 20, 30], $this->orders($pdo, $table, '1 = 1'));
        self::evidence('integer-gap', ['single_row_write' => 15, 'exhausted_neighbors' => [10, 11], 'endpoint_collisions' => 1062]);
    }

    public function testActualWriteCostOfThreeDenseAlternatives(): void
    {
        $pdo = $this->connect();
        foreach ([101, 1001] as $size) {
            foreach (['ordered-slot', 'row-wise', 'offset'] as $method) {
                $table = $this->positions($pdo, 'global');
                $values = array_map(static fn(int $n): string => "($n,$n)", range(1, $size));
                $pdo->exec("INSERT INTO `$table` (id, display_order) VALUES " . implode(', ', $values));
                $pdo->beginTransaction();
                try {
                    $locked = $pdo->query("SELECT id FROM `$table` ORDER BY display_order FOR UPDATE");
                    self::assertInstanceOf(PDOStatement::class, $locked);
                    self::assertCount($size, $locked->fetchAll());
                    $locked->closeCursor();
                    $writes = self::costStatements($table, $size, $method);
                    $started = hrtime(true);
                    $affected = 0;
                    foreach ($writes as $sql) {
                        $count = $pdo->exec($sql);
                        self::assertIsInt($count);
                        $affected += $count;
                    }
                    $microseconds = (int) ((hrtime(true) - $started) / 1000);
                    self::assertSame($method === 'offset' ? 2 * $size : $size + 1, $affected);
                    self::assertSame($method === 'row-wise' ? $size + 1 : ($method === 'offset' ? 2 : 3), count($writes));
                    self::assertSame([...range(2, $size), 1], $this->orders($pdo, $table, '1 = 1'));
                    $pdo->commit();
                } finally {
                    self::endIfActive($pdo);
                }
                self::evidence('cost', ['method' => $method, 'scope_rows' => $size, 'neighbor_rows' => $size - 1, 'update_statements' => count($writes), 'rows_updated' => $affected, 'update_client_us' => $microseconds]);
            }
        }
    }

    /** @return non-empty-list<string> */
    private static function costStatements(string $table, int $size, string $method): array
    {
        if ($method === 'offset') {
            return [
                "UPDATE `$table` SET display_order = display_order + $size WHERE display_order BETWEEN 1 AND $size",
                "UPDATE `$table` SET display_order = CASE WHEN id = $size THEN 1 ELSE display_order - $size + 1 END WHERE display_order > $size",
            ];
        }
        $park = $size + 1;
        $writes = ["UPDATE `$table` SET display_order = $park WHERE id = $size"];
        if ($method === 'row-wise') {
            foreach (range($size - 1, 1) as $id) {
                $writes[] = "UPDATE `$table` SET display_order = display_order + 1 WHERE id = $id";
            }
        } else {
            $writes[] = "UPDATE `$table` SET display_order = display_order + 1 WHERE display_order < $size ORDER BY display_order DESC";
        }
        $writes[] = "UPDATE `$table` SET display_order = 1 WHERE id = $size";

        return $writes;
    }

    /** @return non-empty-string Name of the disposable schema variant. */
    private function positions(PDO $pdo, string $layout): string
    {
        $table = 'it_unique_' . bin2hex(random_bytes(6));
        $key = match ($layout) {
            'global' => 'UNIQUE KEY uq_order (display_order)',
            'bounded' => 'UNIQUE KEY uq_order (display_order), CHECK (display_order BETWEEN 1 AND 4)',
            'scoped' => 'UNIQUE KEY uq_scope_order (scope_key, display_order)',
            'active-null' => 'normalized_scope BIGINT AS (COALESCE(scope_key, 0)) STORED, '
                . 'live_order BIGINT AS (CASE WHEN deleted_at IS NULL THEN display_order ELSE NULL END) STORED, '
                . 'CHECK (scope_key IS NULL OR scope_key > 0), '
                . 'UNIQUE KEY uq_active_order (normalized_scope, live_order), KEY ix_scope_order (scope_key, display_order)',
            default => throw new \LogicException('Unknown proof schema'),
        };
        $pdo->exec("CREATE TABLE `$table` (id INT PRIMARY KEY AUTO_INCREMENT, scope_key BIGINT NULL, display_order BIGINT NOT NULL DEFAULT 1, deleted_at DATETIME NULL, payload VARCHAR(32) NOT NULL DEFAULT '', $key) ENGINE=InnoDB");
        $this->proofTables[] = $table;

        return $table;
    }

    private function seed(PDO $pdo, string $table, string $layout): void
    {
        $scope = $layout === 'scoped' ? '7' : 'NULL';
        $pdo->exec("INSERT INTO `$table` (id, scope_key, display_order) VALUES (1,$scope,1),(2,$scope,2),(3,$scope,3),(4,$scope,4)");
        if ($layout !== 'global') {
            $pdo->exec("INSERT INTO `$table` (id, scope_key, display_order) VALUES (11,8,1)");
        }
        if ($layout === 'active-null') {
            $pdo->exec("INSERT INTO `$table` (id, scope_key, display_order, deleted_at) VALUES (10,NULL,2,'2022-01-01')");
        }
    }

    /** @param non-empty-string $table */
    private static function config(string $table, string $layout): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: $table, scopeColumn: $layout === 'global' ? null : 'scope_key', nullableScope: $layout === 'active-null');
    }

    private static function scope(string $layout): string
    {
        return match ($layout) {
            'global' => '1 = 1',
            'scoped' => 'scope_key = 7',
            'active-null' => 'scope_key IS NULL AND deleted_at IS NULL',
            default => throw new \LogicException('Unknown proof scope'),
        };
    }

    /** @return list<mixed> */
    private function rows(PDO $pdo, string $table): array
    {
        $statement = $pdo->query("SELECT * FROM `$table` ORDER BY id");
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<int> */
    private function orders(PDO $pdo, string $table, string $predicate): array
    {
        $statement = $pdo->query("SELECT display_order FROM `$table` WHERE $predicate ORDER BY id");
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values(array_map(static function (mixed $position): int {
            self::assertIsNumeric($position);
            return (int) $position;
        }, $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function readCommitted(PDO ...$connections): void
    {
        foreach ($connections as $pdo) {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        }
    }

    private function duplicate(PDOException $failure): void
    {
        self::assertSame('23000', self::sqlState($failure));
        self::assertSame(1062, self::driverCode($failure));
    }

    private static function endIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /** @param array<string, mixed> $observations */
    private static function evidence(string $case, array $observations): void
    {
        fwrite(STDOUT, 'UNIQUE-PROOF ' . json_encode(['engine' => self::engine(), 'case' => $case, ...$observations], JSON_THROW_ON_ERROR) . "\n");
    }

    protected function tearDown(): void
    {
        if ($this->proofTables !== []) {
            $cleaner = $this->connect();
            $cleaner->exec('SET SESSION lock_wait_timeout = 10');
            foreach ($this->proofTables as $table) {
                $cleaner->exec("DROP TABLE IF EXISTS `$table`");
            }
            $this->proofTables = [];
        }
        parent::tearDown();
    }
}
