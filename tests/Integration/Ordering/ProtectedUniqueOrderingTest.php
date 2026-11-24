<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\FaultInjectingCursorStatement;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use PDO;
use PDOException;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use SensitiveParameter;

final class ProtectedUniqueOrderingTest extends IntegrationTestCase
{
    private PDO $observer;
    /** @var non-empty-string */
    private string $items;
    private string $anchor;
    private ?int $scope;
    private ProtectedScopedOrderingManager $manager;
    private ?PDO $fixtureAdmin = null;
    private ?string $restrictedAccount = null;
    private ?string $dependencySchema = null;
    /** @var list<PdoProtectedTransactionCheckout> */
    private array $checkouts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = $this->connect();
        $this->observer->exec('SET SESSION lock_wait_timeout = 4');
        $this->items = self::setting('DATABASE') . '.b1_items_' . bin2hex(random_bytes(6));
        $this->anchor = self::setting('DATABASE') . '.b1_anchor_' . bin2hex(random_bytes(6));
        $this->manager = new ProtectedScopedOrderingManager();
    }

    protected function tearDown(): void
    {
        foreach ($this->checkouts as $checkout) {
            if ($checkout->settlement()?->disposition === ProtectedTransactionDispositionEnum::DISCARD_REQUIRED) {
                $checkout->discardForHost();
            } elseif ($checkout->settlement() === null) {
                $checkout->rollBack();
                $checkout->returnForHost();
            }
        }
        $this->checkouts = [];
        if ($this->fixtureAdmin !== null) {
            if ($this->dependencySchema !== null) {
                $this->fixtureAdmin->exec('DROP DATABASE IF EXISTS ' . $this->dependencySchema);
            }
            if ($this->restrictedAccount !== null) {
                $this->fixtureAdmin->exec('DROP USER IF EXISTS ' . $this->restrictedAccount);
            }
        }
        $this->observer->exec('DROP TABLE IF EXISTS ' . self::quoted($this->items));
        $this->observer->exec('DROP TABLE IF EXISTS ' . self::quoted($this->anchor));
        parent::tearDown();
    }

    /** @return iterable<string, array{bool, bool, string, bool}> */
    public static function profiles(): iterable
    {
        yield 'global-native-rc' => [false, false, 'READ COMMITTED', false];
        yield 'global-unsigned-found-rr' => [false, true, 'REPEATABLE READ', true];
        yield 'scoped-fk-signed-rr' => [true, false, 'REPEATABLE READ', false];
        yield 'scoped-fk-unsigned-found-rc' => [true, true, 'READ COMMITTED', true];
    }

    #[DataProvider('profiles')]
    public function testDenseLifecycle(bool $scoped, bool $unsigned, string $isolation, bool $foundRows): void
    {
        $this->createProfile($scoped, $unsigned);
        $config = $this->config(positionLimit: 5, rowLimit: 4);
        $checkout = $this->checkout($isolation, $foundRows);
        $id = $unsigned ? 4294967292 : 10;
        foreach (range($id, $id + 3) as $offset => $rowId) {
            self::assertSame($offset + 1, $this->append($checkout, $config, $rowId));
        }
        $otherScope = $scoped ? 8 : null;
        if ($otherScope !== null) {
            self::assertSame(1, $this->manager->getNextUniquePositionForAppend($checkout, $config, $otherScope));
            $this->insert($checkout, $otherScope, 8, 1);
        }
        self::assertSame([], $this->orderedIds($this->scope));
        $checkout->commit();
        $checkout->returnForHost();
        self::assertSame(range($id, $id + 3), $this->orderedIds($this->scope));

        $checkout = $this->checkout($isolation, $foundRows);
        self::assertTrue($this->manager->moveUniqueWithinScope($checkout, $config, $this->scope, $id + 3, 1));
        self::assertTrue($this->manager->moveUniqueWithinScope($checkout, $config, $this->scope, $id, 4));
        self::assertTrue($this->manager->moveUniqueWithinScope($checkout, $config, $this->scope, $id, 4));
        self::assertFalse($this->manager->moveUniqueWithinScope($checkout, $config, $this->scope, 7, 1));
        self::assertFalse($this->manager->deleteUniqueWithinScope($checkout, $config, $this->scope, 7, 'delete'));
        self::assertSame(range($id, $id + 3), $this->orderedIds($this->scope));
        self::assertTrue($this->manager->deleteUniqueWithinScope($checkout, $config, $this->scope, $id + 3, 'delete')); // first
        self::assertTrue($this->manager->deleteUniqueWithinScope($checkout, $config, $this->scope, $id + 2, 'delete')); // middle
        self::assertTrue($this->manager->deleteUniqueWithinScope($checkout, $config, $this->scope, $id, 'delete')); // last
        self::assertTrue($this->manager->deleteUniqueWithinScope($checkout, $config, $this->scope, $id + 1, 'delete')); // only
        self::assertSame(1, $this->append($checkout, $config, $id));
        $checkout->commit();
        $checkout->returnForHost();
        self::assertSame([$id], $this->orderedIds($this->scope));
        if ($otherScope !== null) {
            self::assertSame([8], $this->orderedIds($otherScope));
        }
    }

    public function testStagingAndScopeCapacityAndHostRollback(): void
    {
        $this->createProfile(false, false);
        $config = $this->config(positionLimit: 3, rowLimit: 3);
        $checkout = $this->checkout();
        self::assertSame(1, $this->append($checkout, $config, 1));
        self::assertSame(2, $this->append($checkout, $config, 2));
        $this->reject('NO_STAGING_POSITION', fn() => $this->append($checkout, $config, 3));
        $this->reject('SCOPE_ROW_LIMIT', fn() => $this->append($checkout, $this->configFrom($config, rowLimit: 2), 3));
        self::assertTrue($this->manager->moveUniqueWithinScope($checkout, $config, null, 2, 1));
        // The qualified profile can read/delete from N=maxPosition even if a
        // controlled migration loaded it before enabling the B1 writers.
        $this->insert($checkout, null, 3, 3);
        $this->reject('NO_STAGING_POSITION', fn() => $this->manager->moveUniqueWithinScope($checkout, $config, null, 1, 1));
        self::assertTrue($this->manager->deleteUniqueWithinScope($checkout, $config, null, 1, 'delete'));
        self::assertTrue($this->manager->moveUniqueWithinScope($checkout, $config, null, 2, 2));
        $checkout->rollBack();
        $checkout->returnForHost();
        self::assertSame([], $this->orderedIds(null));
    }

    /** @return iterable<string, array{string}> */
    public static function excludedProfiles(): iterable
    {
        yield 'extra-unique' => ['UNIQUE (label)'];
        yield 'check' => ['CHECK (pos > 0)'];
        yield 'generated' => ['generated_value INT GENERATED ALWAYS AS (pos + 1) STORED'];
        yield 'automatic-timestamp' => ['changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'];
        yield 'unqualified-fk' => ['FOREIGN KEY (pos) REFERENCES {anchor} (id)'];
    }

    #[DataProvider('excludedProfiles')]
    public function testMatchingFingerprintDoesNotAuthorizeExcludedDdl(string $extra): void
    {
        $this->createProfile(false, false, str_replace('{anchor}', self::quoted($this->anchor), $extra));
        $checkout = $this->checkout();
        $config = $this->config();
        $this->reject('INELIGIBLE_SCHEMA', fn() => $this->append($checkout, $config, 1));
        $checkout->commit();
        $checkout->returnForHost();
        self::assertSame([], $this->orderedIds(null));
    }

    public function testReceiptChecksAndUnverifiedAnchorAndDensityFailBeforeWrites(): void
    {
        $this->createProfile(true, false);
        $config = $this->config();
        $stale = $this->config(receipt: ['schemaRevision' => 'older']);
        $incomplete = $this->config(receipt: ['writersReviewed' => false]);
        $checkout = $this->checkout();
        foreach ([$stale, $incomplete] as $unverified) {
            $this->reject('SCHEMA_UNVERIFIED', fn() => $this->append($checkout, $unverified, 2));
        }
        $this->reject('ANCHOR_MISSING', fn() => $this->manager->getNextUniquePositionForAppend($checkout, $config, 99));
        $checkout->rollBack();
        $checkout->returnForHost();

        $this->observer->exec('INSERT INTO ' . self::quoted($this->items) . ' (id, scope_id, pos) VALUES (2, 7, 2)');
        $checkout = $this->checkout();
        $this->reject('SCOPE_NOT_DENSE', fn() => $this->manager->deleteUniqueWithinScope($checkout, $config, 7, 2, 'delete'));
        $checkout->rollBack();
        $checkout->returnForHost();
        self::assertSame([2], $this->orderedIds(7));

        $this->observer->exec('ALTER TABLE ' . self::quoted($this->items) . ' ADD COLUMN drift INT DEFAULT NULL');
        $checkout = $this->checkout();
        $this->reject('SCHEMA_UNVERIFIED', fn() => $this->append($checkout, $config, 3));
        $checkout->rollBack();
        $checkout->returnForHost();
    }

    public function testTemporaryShadowAndDisabledChecksAreRejected(): void
    {
        $this->createProfile(false, false);
        $config = $this->config();
        $table = self::quoted($this->items);
        $checkout = $this->checkout(sessionSetup: [
            "CREATE TEMPORARY TABLE $table (id INT NOT NULL PRIMARY KEY, pos INT NOT NULL, UNIQUE (pos)) ENGINE=InnoDB",
        ]);
        $this->reject('INELIGIBLE_SCHEMA', fn() => $this->append($checkout, $config, 1));
        $checkout->rollBack();
        $checkout->returnForHost();

        foreach (['unique_checks', 'foreign_key_checks'] as $setting) {
            $checkout = $this->checkout(sessionSetup: ["SET SESSION $setting = 0"]);
            $this->reject('SCHEMA_UNVERIFIED', fn() => $this->append($checkout, $config, 1));
            $checkout->rollBack();
            $checkout->returnForHost();
        }
        self::assertSame([], $this->orderedIds(null));
    }

    public function testMetadataRemainsLockedUntilHostCompletion(): void
    {
        $this->createProfile(false, false);
        $checkout = $this->checkout();
        self::assertSame(1, $this->manager->getNextUniquePositionForAppend($checkout, $this->config(), null));
        $this->observer->exec('SET SESSION lock_wait_timeout = 1');
        $ddl = 'ALTER TABLE ' . self::quoted($this->items) . ' ADD COLUMN reviewed_change INT';
        self::assertSame(1205, self::driverCode(self::failureOf(fn() => $this->observer->exec($ddl))));
        $checkout->rollBack();
        $checkout->returnForHost();
        self::assertSame(0, $this->observer->exec($ddl));
    }

    public function testLockingReadUsesCurrentStateOrFencesMariaDbStaleSnapshot(): void
    {
        $this->createProfile(true, false);
        $config = $this->config();
        $early = $this->checkout();
        self::assertSame(1, $this->append($early, $config, 1));
        $early->commit();
        $early->returnForHost();

        $mariaDb = self::engine() === 'mariadb';
        $relay = $this->relay('after', 'snapshot-read');
        try {
            $stale = $this->checkout(
                'REPEATABLE READ',
                sessionSetup: $mariaDb ? ['SET SESSION innodb_snapshot_isolation = ON'] : [],
                relayPort: $this->relayPort($relay),
            );
            self::assertSame([['n' => 1]], $stale->runRegisteredCommand('snapshot'));

            $writer = $this->checkout();
            self::assertSame(2, $this->append($writer, $config, 2));
            $writer->commit();
            $writer->returnForHost();

            $allocate = fn(): int => $this->manager->getNextUniquePositionForAppend($stale, $config, 7);
            if ($mariaDb) {
                $failure = self::failureOf($allocate);
                self::assertSame(1020, self::driverCode($failure));
                self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $stale->settlement()?->disposition);
                self::assertSame(ProtectedTransactionReasonEnum::TRANSACTION_LOST, $stale->settlement()->reason);
            } else {
                self::assertSame(3, $allocate());
                $stale->rollBack();
                $stale->returnForHost();
            }
        } finally {
            $relay->stop();
        }
        self::assertSame([1, 2], $this->orderedIds(7));
    }

    public function testNoOpAndAbsentTargetDoNotOpenASavepoint(): void
    {
        $this->createProfile(false, false);
        $this->seedFaultProfile();
        $config = $this->config();
        $relay = PartnerProcess::start([
            PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'SAVEPOINT', 'before',
        ]);
        try {
            $checkout = $this->checkout(relayPort: $this->relayPort($relay));
            self::assertTrue($this->manager->moveUniqueWithinScope($checkout, $config, null, 1, 1));
            self::assertFalse($this->manager->moveUniqueWithinScope($checkout, $config, null, 99, 1));
            self::assertFalse($this->manager->deleteUniqueWithinScope($checkout, $config, null, 99, 'delete'));
            $checkout->commit();
            $checkout->returnForHost();
        } finally {
            $relay->stop();
        }
        self::assertSame([1, 2, 3], $this->orderedIds(null));
    }

    public function testRegisteredDeleteRejectsArbitraryPredicatesAndWrongBindingContract(): void
    {
        $this->createProfile(false, false);
        $config = $this->config();
        $checkout = $this->checkout();
        self::assertSame(1, $this->append($checkout, $config, 8));
        try {
            $this->manager->deleteUniqueWithinScope($checkout, $config, null, 8, 'other-delete');
            self::fail('A delete with an additional predicate was accepted.');
        } catch (InvalidOrderingOperationException) {
            self::assertNull($checkout->settlement());
        }
        try {
            $this->manager->deleteUniqueWithinScope($checkout, $config, null, 8, 'wrong-bindings');
            self::fail('A delete with a different binding contract was accepted.');
        } catch (ProtectedTransactionException) {
            self::assertNull($checkout->settlement());
        }
        $checkout->commit();
        $checkout->returnForHost();
        self::assertSame([8], $this->orderedIds(null));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function failurePhases(): iterable
    {
        foreach (['precheck', 'park', 'shift', 'finalize', 'delete', 'compact', 'verify', 'undo'] as $phase) {
            yield $phase => [$phase, in_array($phase, ['delete', 'compact'], true)];
        }
        yield 'lost release acknowledgement' => ['after', false];
        yield 'loss after release acknowledgement' => ['after-ack', true];
    }

    #[DataProvider('failurePhases')]
    public function testDriverFailureAtEachDmlPhaseFencesCheckout(string $phase, bool $delete): void
    {
        $this->createProfile(false, false);
        $this->seedFaultProfile();
        $config = $this->config();
        $releaseFault = in_array($phase, ['after', 'after-ack'], true);
        $relay = $this->relay($releaseFault ? $phase : 'b1-invalid-statement', $phase);
        try {
            $checkout = $this->checkout(relayPort: $this->relayPort($relay));
            self::assertSame(1, $checkout->runRegisteredCommand('mark', ['id' => 1]));
            $operation = $delete
                ? fn() => $this->manager->deleteUniqueWithinScope($checkout, $config, null, 1, 'delete')
                : fn() => $this->manager->moveUniqueWithinScope($checkout, $config, null, 3, 1);
            if ($phase === 'undo') {
                $this->reject('MOVE_INVARIANT_FAILED', $operation);
                self::assertSame('B1 PHASE shift', $relay->readLine(12.0));
            } else {
                $failure = self::failureOf($operation);
                if (!$releaseFault) {
                    self::assertSame(30001, self::driverCode($failure));
                }
            }
            $expected = match ($phase) {
                'after' => 'SERVER OK WITHHELD',
                'after-ack' => 'SERVER OK FORWARDED',
                default => 'B1 PHASE ' . $phase,
            };
            self::assertSame($expected, $relay->readLine(12.0));
            self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $checkout->settlement()?->disposition);
            $this->expectException(ProtectedTransactionException::class);
            $checkout->commit();
        } finally {
            $relay->stop();
            self::assertSame([1, 2, 3], $this->orderedIds(null));
            self::assertSame('original', $this->scalar($this->observer, $this->markerSql()));
        }
    }

    public function testConfirmedLocalUndoPreservesHostMarkerAndDenseOrder(): void
    {
        $this->createProfile(false, false);
        $this->seedFaultProfile();
        $config = $this->config();
        $relay = $this->relay('b1-empty-update', 'compact');
        try {
            $checkout = $this->checkout(relayPort: $this->relayPort($relay));
            self::assertSame(1, $checkout->runRegisteredCommand('mark', ['id' => 1]));
            $this->reject('DELETE_INVARIANT_FAILED', fn() => $this->manager->deleteUniqueWithinScope($checkout, $config, null, 1, 'delete'));
            self::assertSame('B1 PHASE compact', $relay->readLine(12.0));
            self::assertNull($checkout->settlement());
            $checkout->commit();
            $checkout->returnForHost();
        } finally {
            $relay->stop();
        }
        self::assertSame([1, 2, 3], $this->orderedIds(null));
        self::assertSame('pending', $this->scalar($this->observer, $this->markerSql()));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testUnconfirmedCursorCloseFencesRealCheckoutAndPreservesFirstThrowable(bool $executeAlsoFails): void
    {
        $this->createProfile(false, false);
        $this->seedFaultProfile();
        $config = $this->config();
        $closeFailure = new PDOException('Injected cursor close failure after real driver close.');
        $executeFailure = $executeAlsoFails ? new PDOException('Injected execute failure after real driver write.') : null;
        $checkout = $this->checkout(cursorFault: [$closeFailure, $executeFailure]);
        self::assertSame(1, $checkout->runRegisteredCommand('mark', ['id' => 1]));

        $primary = self::failureOf(fn() => $this->manager->moveUniqueWithinScope($checkout, $config, null, 3, 1));

        self::assertSame($executeFailure ?? $closeFailure, $primary);
        $settlement = $checkout->settlement();
        self::assertNotNull($settlement);
        self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $settlement->disposition);
        self::assertSame(ProtectedTransactionReasonEnum::OPERATION_UNCONFIRMED, $settlement->reason);
        foreach ([
            fn() => $checkout->runRegisteredCommand('mark', ['id' => 2]),
            fn() => $this->manager->getNextUniquePositionForAppend($checkout, $config, null),
            $checkout->commit(...),
            $checkout->rollBack(...),
            $checkout->returnForHost(...),
        ] as $laterCommand) {
            try {
                $laterCommand();
                self::fail('A fenced checkout accepted subsequent work.');
            } catch (ProtectedTransactionException) {
                self::assertSame($settlement, $checkout->settlement());
            }
        }
        self::assertSame('original', $this->scalar($this->observer, $this->markerSql()));
        $checkout->discardForHost();
        self::assertSame($checkout, array_pop($this->checkouts));

        // Synchronize with server-side disconnect/rollback by taking the marker lock.
        $this->observer->exec('SET SESSION innodb_lock_wait_timeout = 4');
        $this->observer->beginTransaction();
        try {
            self::assertSame('original', $this->scalar($this->observer, $this->markerSql() . ' FOR UPDATE'));
            self::assertSame([1, 2, 3], $this->orderedIds(null));
        } finally {
            $this->observer->rollBack();
        }
    }

    public function testLimitedAccountRequiresVisibleRulesForReviewedScopeToAnchorForeignKey(): void
    {
        $this->createProfile(true, false);
        [$user, $password] = $this->createRestrictedAccount();
        $this->observer->exec('INSERT INTO ' . self::quoted($this->items) . ' (id, scope_id, pos) VALUES (1, 7, 1), (2, 7, 2)');
        [$schema, $table] = explode('.', $this->items);
        $rulesSql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '$schema'"
            . " AND TABLE_NAME = '$table' AND UPDATE_RULE = 'RESTRICT' AND DELETE_RULE = 'RESTRICT'";
        $admin = $this->fixtureAdmin;
        self::assertNotNull($admin);
        self::assertSame('1', $this->scalar($admin, $rulesSql));

        $limited = $this->fixtureConnection($user, $password);
        $rulesVisible = $this->scalar($limited, $rulesSql);
        $checkout = $this->checkout(connection: $limited);
        unset($limited);
        $config = $this->config();
        if ($rulesVisible === '0') {
            $this->reject('SCHEMA_UNVERIFIED', fn() => $this->manager->moveUniqueWithinScope($checkout, $config, 7, 2, 1));
            self::assertNull($checkout->settlement());
            $checkout->commit();
            $checkout->returnForHost();
            self::assertSame([1, 2], $this->orderedIds(7));

            return;
        }

        self::assertSame('1', $rulesVisible);
        self::assertSame(3, $this->manager->getNextUniquePositionForAppend($checkout, $config, 7));
        self::assertTrue($this->manager->moveUniqueWithinScope($checkout, $config, 7, 2, 1));
        $checkout->commit();
        $checkout->returnForHost();
        self::assertSame([2, 1], $this->orderedIds(7));
    }

    #[TestWith(['incoming-foreign-key'])]
    #[TestWith(['trigger'])]
    public function testLimitedAccountUsesReceiptWhereDependencyVisibilityIsIncomplete(string $dependency): void
    {
        $this->createProfile(false, false);
        [$user, $password] = $this->createRestrictedAccount();
        $admin = $this->fixtureAdmin;
        self::assertNotNull($admin);
        [$schema, $table] = explode('.', $this->items);
        if ($dependency === 'incoming-foreign-key') {
            $this->dependencySchema = 'b1_dep_' . bin2hex(random_bytes(6));
            $child = $this->dependencySchema . '.items_reference';
            $admin->exec('CREATE DATABASE ' . $this->dependencySchema);
            $admin->exec('CREATE TABLE ' . $child . ' (id INT PRIMARY KEY, item_id INT NOT NULL,'
                . ' FOREIGN KEY (item_id) REFERENCES ' . self::quoted($this->items) . ' (id)) ENGINE=InnoDB');
            $metadataSql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = '$schema'"
                . " AND REFERENCED_TABLE_NAME = '$table'";
            $reveal = 'GRANT SELECT ON ' . $child;
        } else {
            $admin->exec('CREATE TRIGGER ' . self::quoted($schema . '.b1_trigger_' . bin2hex(random_bytes(6)))
                . ' BEFORE UPDATE ON ' . self::quoted($this->items) . ' FOR EACH ROW SET NEW.label = NEW.label');
            $metadataSql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = '$schema'"
                . " AND EVENT_OBJECT_TABLE = '$table'";
            $reveal = 'GRANT TRIGGER ON ' . self::quoted($this->items);
        }
        self::assertSame('1', $this->scalar($admin, $metadataSql));
        $limited = $this->fixtureConnection($user, $password);
        $seenByLimitedAccount = $this->scalar($limited, $metadataSql);
        if ($dependency === 'incoming-foreign-key' || self::engine() === 'mysql') {
            self::assertSame('0', $seenByLimitedAccount);
        }
        $checkout = $this->checkout(connection: $limited);
        unset($limited);
        $missingReview = $this->config(receipt: ['dependenciesReviewed' => false]);
        $this->reject('SCHEMA_UNVERIFIED', fn() => $this->manager->getNextUniquePositionForAppend($checkout, $missingReview, null));
        $staleFingerprint = $this->config(receipt: ['itemsFingerprint' => str_repeat('0', 64)]);
        $this->reject('SCHEMA_UNVERIFIED', fn() => $this->manager->getNextUniquePositionForAppend($checkout, $staleFingerprint, null));
        self::assertNull($checkout->settlement());

        $assertedReview = $this->config();
        if ($seenByLimitedAccount === '0') {
            // Deliberately incorrect receipt in a read-only allocator call: it
            // demonstrates the conditional trust boundary and performs no DML.
            self::assertSame(1, $this->manager->getNextUniquePositionForAppend($checkout, $assertedReview, null));
        } else {
            self::assertSame('1', $seenByLimitedAccount);
            $this->reject('INELIGIBLE_SCHEMA', fn() => $this->manager->getNextUniquePositionForAppend($checkout, $assertedReview, null));
        }
        $checkout->rollBack();
        $checkout->returnForHost();
        $admin->exec($reveal . ' TO ' . $this->restrictedAccount);

        $visible = $this->fixtureConnection($user, $password);
        self::assertSame('1', $this->scalar($visible, $metadataSql));
        $checkout = $this->checkout(connection: $visible);
        unset($visible);
        $this->reject('INELIGIBLE_SCHEMA', fn() => $this->manager->getNextUniquePositionForAppend($checkout, $assertedReview, null));
        self::assertNull($checkout->settlement());
        $checkout->rollBack();
        $checkout->returnForHost();
        self::assertSame([], $this->orderedIds(null));
    }

    /** @return array{string, string} */
    private function createRestrictedAccount(): array
    {
        $admin = $this->fixtureConnection('root', self::setting('ADMIN_PASSWORD'));
        $admin->exec('SET SESSION lock_wait_timeout = 4');
        $this->fixtureAdmin = $admin;
        $user = 'b1_user_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $this->restrictedAccount = "'" . $user . "'@'%'";
        $admin->exec("CREATE USER " . $this->restrictedAccount . " IDENTIFIED BY '" . $password . "'");
        foreach ([$this->items, $this->anchor] as $table) {
            $admin->exec('GRANT SELECT, UPDATE ON ' . self::quoted($table) . ' TO ' . $this->restrictedAccount);
        }

        return [$user, $password];
    }

    private function fixtureConnection(string $user, #[SensitiveParameter] string $password): PDO
    {
        return new PDO(
            'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE'),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    private function relay(string $stage, string $phase): PartnerProcess
    {
        return PartnerProcess::start([
            PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'RELEASE SAVEPOINT', $stage, $phase,
        ]);
    }

    private function relayPort(PartnerProcess $relay): string
    {
        if (preg_match('/\AREADY 127\.0\.0\.1:([0-9]+)\z/', $relay->readLine(12.0), $endpoint) !== 1) {
            self::fail('The unique ordering test relay did not start.');
        }

        return $endpoint[1];
    }

    private function seedFaultProfile(): void
    {
        $this->observer->exec('INSERT INTO ' . self::quoted($this->items) . " (id, pos, label) VALUES (1, 1, 'original'), (2, 2, 'second'), (3, 3, 'third')");
    }

    private function markerSql(): string
    {
        return 'SELECT label FROM ' . self::quoted($this->items) . ' WHERE id = 1';
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function contentionCases(): iterable
    {
        yield 'first/first RC commit' => ['append', 'READ COMMITTED', true];
        yield 'first/first RR rollback' => ['append', 'REPEATABLE READ', false];
        yield 'move/move RR commit' => ['move', 'REPEATABLE READ', true];
        yield 'move/append RC rollback' => ['move-append', 'READ COMMITTED', false];
        yield 'delete/delete RC commit' => ['delete', 'READ COMMITTED', true];
        yield 'delete/move RC rollback' => ['delete-move', 'READ COMMITTED', false];
        yield 'delete/append RR commit' => ['delete-append', 'REPEATABLE READ', true];
    }

    #[DataProvider('contentionCases')]
    public function testScopeAnchorSerializesCooperatingWriters(string $pair, string $isolation, bool $commit): void
    {
        $this->createProfile(true, false);
        $config = $this->config();
        $first = $this->checkout($isolation);
        if ($pair !== 'append') {
            self::assertSame(1, $this->append($first, $config, 1));
            self::assertSame(2, $this->append($first, $config, 2));
            $first->commit();
            $first->returnForHost();
            $first = $this->checkout($isolation);
        }
        $move = fn(PdoProtectedTransactionCheckout $checkout): bool => $this->manager->moveUniqueWithinScope($checkout, $config, 7, 2, 1);
        $delete = fn(PdoProtectedTransactionCheckout $checkout): bool => $this->manager->deleteUniqueWithinScope($checkout, $config, 7, 2, 'delete');
        match ($pair) {
            'append' => self::assertSame(1, $this->append($first, $config, 1)),
            'move', 'move-append' => self::assertTrue($move($first)),
            default => self::assertTrue($delete($first)),
        };

        $operation = match ($pair) {
            'move', 'delete-move' => $move,
            'delete' => $delete,
            default => fn(PdoProtectedTransactionCheckout $checkout): int => $this->append($checkout, $config, 3),
        };
        // A separate worker owns the contending session, as a real Host
        // worker would. Its exit also closes any driver-held resources.
        $contender = $this->contender($config, $pair, $isolation);
        try {
            self::assertSame('1205 DISCARD_REQUIRED', $contender->readLine(12.0));
        } finally {
            $contender->stop();
        }
        self::assertSame($pair === 'append' ? [] : [1, 2], $this->orderedIds(7));
        if ($commit) {
            $first->commit();
        } else {
            $first->rollBack();
        }
        $first->returnForHost();
        $after = $this->checkout($isolation);
        self::assertSame(match ($pair) {
            'append' => $commit ? 2 : 1,
            'delete' => false,
            'delete-append' => 2,
            'move-append' => 3,
            default => true,
        }, $operation($after));
        $after->commit();
        $after->returnForHost();
        self::assertSame(match ($pair) {
            'append' => $commit ? [1, 3] : [3],
            'delete' => [1],
            'delete-append' => [1, 3],
            'move-append' => [1, 2, 3],
            default => [2, 1],
        }, $this->orderedIds(7));
    }

    #[TestWith([true, 'READ COMMITTED'])]
    #[TestWith([false, 'REPEATABLE READ'])]
    public function testWaitingFirstAppendUsesPostCompletionState(bool $commit, string $isolation): void
    {
        $this->createProfile(true, false);
        $config = $this->config();
        $first = $this->checkout($isolation);
        self::assertSame(1, $this->append($first, $config, 1));
        $second = $this->contender($config, 'wait', $isolation);
        try {
            $this->waitForAnchorRead($second);
            self::assertSame([], $this->orderedIds(7));
            if ($commit) {
                $first->commit();
            } else {
                $first->rollBack();
            }
            $first->returnForHost();
            self::assertSame($commit ? 'INSERTED 2' : 'INSERTED 1', $second->readLine(12.0));
        } finally {
            $second->stop();
        }
        self::assertSame($commit ? [1, 99] : [99], $this->orderedIds(7));
    }

    public function testARealAnchorDeadlockFencesTheLighterTransaction(): void
    {
        $this->createProfile(true, false);
        $config = $this->config();
        $first = $this->checkout();
        foreach (range(1, 10) as $id) {
            self::assertSame($id, $this->append($first, $config, $id));
        }
        // Reverse anchor acquisition is deliberate in this failure fixture.
        $second = $this->contender($config, 'deadlock', 'READ COMMITTED');
        try {
            $this->waitForAnchorRead($second);
            self::assertSame(1, $this->manager->getNextUniquePositionForAppend($first, $config, 8));
            self::assertSame('1213 DISCARD_REQUIRED', $second->readLine(12.0));
            $first->commit();
            $first->returnForHost();
        } finally {
            $second->stop();
        }
        self::assertSame(range(1, 10), $this->orderedIds(7));
        self::assertSame([], $this->orderedIds(8));
    }

    private function contender(UniqueOrderingConfig $config, string $pair, string $isolation): PartnerProcess
    {
        return PartnerProcess::start([
            PHP_BINARY, __DIR__ . '/UniqueOrderingContender.php',
            $this->items, $this->anchor, $isolation, $pair,
            json_encode($config->schemaAttestation, JSON_THROW_ON_ERROR),
        ]);
    }

    private function waitForAnchorRead(PartnerProcess $writer): void
    {
        $ready = $writer->readLine(12.0);
        self::assertMatchesRegularExpression('/\AREADY [1-9][0-9]*\z/', $ready);
        $threadId = (int) substr($ready, 6);
        $writer->writeLine('START');
        $deadline = microtime(true) + 5.0;
        do {
            $result = $this->observer->query('SHOW FULL PROCESSLIST');
            self::assertNotFalse($result);
            $sessions = $result->fetchAll(PDO::FETCH_ASSOC);
            $result->closeCursor();
            foreach ($sessions as $session) {
                self::assertIsArray($session);
                $id = $session['Id'] ?? null;
                $sql = $session['Info'] ?? null;
                if (($id === $threadId || $id === (string) $threadId)
                    && is_string($sql) && str_contains($sql, self::quoted($this->anchor)) && str_ends_with($sql, 'FOR UPDATE')
                ) {
                    return;
                }
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('The contender did not reach the anchor locking read.');
    }

    private function createProfile(bool $scoped, bool $unsigned, string $extra = ''): void
    {
        $type = $unsigned ? 'INT UNSIGNED' : 'INT';
        $this->scope = $scoped ? ($unsigned ? 4294967295 : 7) : null;
        $this->observer->exec('CREATE TABLE ' . self::quoted($this->anchor) . " (id $type NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        $this->observer->exec('INSERT INTO ' . self::quoted($this->anchor) . ' (id) VALUES (' . ($this->scope ?? 1) . '), (8)');
        $scope = $scoped ? ", scope_id $type NOT NULL" : '';
        $key = $scoped ? 'scope_id, pos' : 'pos';
        $fk = $scoped ? ', FOREIGN KEY (scope_id) REFERENCES ' . self::quoted($this->anchor) . ' (id) ON DELETE RESTRICT ON UPDATE RESTRICT' : '';
        $this->observer->exec('CREATE TABLE ' . self::quoted($this->items) . " (id $type NOT NULL PRIMARY KEY, pos $type NOT NULL, label VARCHAR(64)"
            . $scope . ", UNIQUE ($key)" . $fk . ($extra === '' ? '' : ', ' . $extra) . ') ENGINE=InnoDB');
    }

    /** @param array<string, mixed> $receipt */
    private function config(int $positionLimit = 32, int $rowLimit = 16, array $receipt = []): UniqueOrderingConfig
    {
        return new UniqueOrderingConfig(
            ordering: new ScopedOrderingConfig(
                table: $this->items,
                scopeColumn: $this->scope === null ? null : 'scope_id',
                orderColumn: 'pos',
                deletedAtColumn: null,
            ),
            anchorTable: $this->anchor,
            anchorIdColumn: 'id',
            globalAnchorId: $this->scope === null ? 1 : null,
            maxPosition: $positionLimit,
            maxScopeRows: $rowLimit,
            schemaAttestation: $receipt + [
                'formatVersion' => 1,
                'schemaRevision' => 'it-revision-1',
                'serverVersion' => $this->scalar($this->observer, 'SELECT VERSION()'),
                'itemsTable' => $this->items,
                'anchorTable' => $this->anchor,
                'itemsFingerprint' => $this->fingerprint($this->items),
                'anchorFingerprint' => $this->fingerprint($this->anchor),
                'dependenciesReviewed' => true,
                'writersReviewed' => true,
                'anchorProvisioned' => true,
            ],
            activeSchemaRevision: 'it-revision-1',
        );
    }

    private function configFrom(UniqueOrderingConfig $previous, int $rowLimit): UniqueOrderingConfig
    {
        return new UniqueOrderingConfig(
            $previous->ordering,
            $this->anchor,
            'id',
            $previous->globalAnchorId,
            $previous->maxPosition,
            $rowLimit,
            $previous->schemaAttestation,
            $previous->activeSchemaRevision,
        );
    }

    private function fingerprint(string $table): string
    {
        $result = $this->observer->query('SHOW CREATE TABLE ' . self::quoted($table));
        self::assertNotFalse($result);
        $ddl = $result->fetchColumn(1);
        self::assertIsString($ddl);

        return hash('sha256', $ddl);
    }

    /**
     * @param list<string> $sessionSetup
     * @param array{PDOException, ?PDOException}|null $cursorFault
     */
    private function checkout(
        string $isolation = 'READ COMMITTED',
        bool $foundRows = false,
        array $sessionSetup = [],
        ?string $relayPort = null,
        ?PDO $connection = null,
        ?array $cursorFault = null,
    ): PdoProtectedTransactionCheckout {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => $relayPort !== null,
            Mysql::ATTR_FOUND_ROWS => $foundRows,
        ];
        $pdo = $connection ?? new PDO(
            'mysql:host=' . self::setting('HOST') . ';port=' . ($relayPort ?? self::setting('PORT')) . ';dbname=' . self::setting('DATABASE'),
            self::setting('USER'),
            self::setting('PASSWORD'),
            $options,
        );
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
        foreach ($sessionSetup as $sql) {
            $pdo->exec($sql);
        }
        $scoped = $this->scope !== null;
        $table = self::quoted($this->items);
        $scopeSql = $scoped ? ' AND ' . chr(96) . 'scope_id' . chr(96) . ' = :scope_id' : '';
        $whereId = ' WHERE ' . chr(96) . 'id' . chr(96) . ' = :id';
        $delete = 'DELETE FROM ' . $table . $whereId . $scopeSql . ' AND ' . chr(96) . 'pos' . chr(96) . ' = :position';
        $params = $scoped ? ['id', 'scope_id', 'position'] : ['id', 'position'];
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
            'insert' => new ProtectedTransactionCommandPlan($scoped
                ? "INSERT INTO $table (id, scope_id, pos) VALUES (:id, :scope_id, :position)"
                : "INSERT INTO $table (id, pos) VALUES (:id, :position)", $params),
            'delete' => new ProtectedTransactionCommandPlan($delete, $params),
            'snapshot' => new ProtectedTransactionCommandPlan("SELECT COUNT(*) AS n FROM $table", [], 1),
            'mark' => new ProtectedTransactionCommandPlan("UPDATE $table SET label = 'pending' WHERE id = :id", ['id']),
            'other-delete' => new ProtectedTransactionCommandPlan($delete . ' AND label IS NULL', $params),
            'wrong-bindings' => new ProtectedTransactionCommandPlan($delete, ['id']),
        ]));
        $this->checkouts[] = $checkout;
        if ($cursorFault !== null) {
            // Test seam: install a statement wrapper after the real checkout
            // accepts the default statement class, then release the PDO alias.
            self::assertTrue($pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FaultInjectingCursorStatement::class, $cursorFault]));
        }
        unset($pdo, $connection);
        $checkout->begin();

        return $checkout;
    }

    private function append(PdoProtectedTransactionCheckout $checkout, UniqueOrderingConfig $config, int $id): int
    {
        $position = $this->manager->getNextUniquePositionForAppend($checkout, $config, $this->scope);
        $this->insert($checkout, $this->scope, $id, $position);

        return $position;
    }

    private function insert(PdoProtectedTransactionCheckout $checkout, ?int $scope, int $id, int $position): void
    {
        $bindings = ['id' => $id, 'position' => $position] + ($scope === null ? [] : ['scope_id' => $scope]);
        self::assertSame(1, $checkout->runRegisteredCommand('insert', $bindings));
    }

    /** @return list<int> */
    private function orderedIds(?int $scope): array
    {
        $result = $this->observer->query('SELECT id FROM ' . self::quoted($this->items)
            . ($scope === null ? '' : " WHERE scope_id = $scope") . ' ORDER BY pos');
        self::assertNotFalse($result);

        return array_values(array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $result->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function reject(string $reason, callable $operation): void
    {
        try {
            $operation();
        } catch (OrderingExecutionException $exception) {
            self::assertSame($reason, $exception->getMeta()['reason'] ?? null);

            return;
        }
        self::fail('B1 unexpectedly accepted ' . $reason);
    }

    private static function quoted(string $qualified): string
    {
        $mark = chr(96);

        return $mark . str_replace('.', $mark . '.' . $mark, $qualified) . $mark;
    }
}
