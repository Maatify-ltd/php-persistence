<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use Closure;
use Error;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use WeakReference;

final class PdoProtectedTransactionCheckoutTest extends IntegrationTestCase
{
    public function testAnUnqualifiedCheckoutReturnsUnchangedAndCannotBeReused(): void
    {
        $pdo = $this->connect();
        $catalog = new ProtectedTransactionCommandCatalog([]);
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog);
        self::assertUnsettled($checkout);
        $this->reject(static fn() => PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog));
        $this->reject(static fn() => $checkout->commit());
        self::assertSame($pdo, $checkout->returnForHost());
        self::assertSame(ProtectedTransactionDispositionEnum::Unchanged, $checkout->settlement()?->disposition);
        $this->reject(static fn() => $checkout->begin());
        $this->reject(static fn() => $checkout->returnForHost());
        $new = PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog);
        self::assertSame($pdo, $new->returnForHost());
    }

    public function testAttachmentFailedWorkAndEarlyCancellationSendNoSql(): void
    {
        $relay = PartnerProcess::start([
            PHP_BINARY,
            dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php',
            'START TRANSACTION READ WRITE',
            'prequalification-no-sql',
        ]);
        try {
            $ready = $relay->readLine(12.0);
            if (preg_match('/\AREADY 127\.0\.0\.1:([0-9]+)\z/', $ready, $endpoint) !== 1) {
                self::fail('The protected transaction test relay did not start.');
            }
            $pdo = new PDO(
                'mysql:host=127.0.0.1;port=' . $endpoint[1] . ';dbname=' . self::setting('DATABASE'),
                self::setting('USER'),
                self::setting('PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_TIMEOUT => 8],
            );
            $catalog = new ProtectedTransactionCommandCatalog(['read' => new ProtectedTransactionCommandPlan('SELECT 1', [], 1)]);
            $this->reject(static fn() => PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog));
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog);
            unset($pdo);
            $this->reject(static fn() => $checkout->runRegisteredCommand('read'));
            $this->reject(static fn() => $checkout->rollBack());
            $returned = $checkout->returnForHost();
            self::assertSame(ProtectedTransactionReasonEnum::CancelledBeforeQualification, $checkout->settlement()?->reason);
            unset($returned);
            self::assertSame('NO SQL BEFORE HANDOFF', $relay->readLine(12.0));
        } finally {
            $relay->stop();
        }
    }

    public function testHostChoosesTransactionEndAndReceivesSameConnection(): void
    {
        $pdo = $this->connect();
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        $checkout->begin();
        self::assertTrue($pdo->inTransaction());
        self::assertUnsettled($checkout);
        $this->reject(static fn() => $checkout->returnForHost());
        $checkout->rollBack();
        self::assertFalse($pdo->inTransaction());
        $settlement = $checkout->settlement();
        self::assertSame(ProtectedTransactionDispositionEnum::Reusable, $settlement?->disposition);
        self::assertSame([
            'disposition' => 'REUSABLE',
            'reason' => 'ROLLED_BACK',
        ], json_decode(json_encode($settlement, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($pdo, $checkout->returnForHost());
        self::assertSame($settlement, $checkout->settlement());
    }

    public function testFailedServerQualificationFencesEvenAnUnstartedCheckout(): void
    {
        $pdo = $this->connect();
        $pdo->exec('SET SESSION autocommit = 0');
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        self::assertUnsettled($checkout);
        $this->reject(static fn() => $checkout->begin());
        $settlement = $checkout->settlement();
        self::assertSame(ProtectedTransactionDispositionEnum::DiscardRequired, $settlement?->disposition);
        self::assertSame(ProtectedTransactionReasonEnum::ServerQualificationFailed, $settlement->reason);
        $this->reject(static fn() => $checkout->returnForHost());
        $this->reject(static fn() => $checkout->rollBack());
        $checkout->discardForHost();
        self::assertSame($settlement, $checkout->settlement());
        $this->reject(static fn() => PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([])));
    }

    public function testCheckoutCannotBeClonedSerializedOrRestored(): void
    {
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($this->connect(), new ProtectedTransactionCommandCatalog([]));
        $copy = static fn(object $value): object => clone($value);
        try {
            self::fail(sprintf('Expected PHP to reject cloning %s.', $copy($checkout)::class));
        } catch (Error $failure) {
            self::assertStringContainsString('__clone', $failure->getMessage());
        }
        $this->reject(static fn() => serialize($checkout));
        $class = PdoProtectedTransactionCheckout::class;
        $encoded = sprintf('O:%d:"%s":0:{}', strlen($class), $class);
        $this->reject(static fn() => unserialize($encoded, ['allowed_classes' => [$class]]));
        self::assertUnsettled($checkout);
        $checkout->returnForHost();
    }

    public function testTheRegistryDoesNotKeepTheConnectionAliveAfterDiscard(): void
    {
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');
        $pdo = new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'));
        $connection = WeakReference::create($pdo);
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        unset($pdo);
        $checkout->discardForHost();
        self::assertNull($connection->get());
    }

    public function testHostWritesRemainUncommittedUntilExplicitCommit(): void
    {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($this->connect(), new ProtectedTransactionCommandCatalog([
            'insert' => new ProtectedTransactionCommandPlan("INSERT INTO `$table` (id, v) VALUES (1, :v)", ['v']),
            'read' => new ProtectedTransactionCommandPlan("SELECT v FROM `$table`", [], 1),
        ]));
        $checkout->begin();
        $this->reject(static fn() => $checkout->runRegisteredCommand('insert'));
        self::assertSame(1, $checkout->runRegisteredCommand('insert', ['v' => 10]));
        self::assertSame([['v' => 10]], $checkout->runRegisteredCommand('read'));
        self::assertSame([], $this->values($observer, $table));
        $checkout->commit();
        self::assertSame([10], $this->values($observer, $table));
        self::assertSame(ProtectedTransactionReasonEnum::Committed, $checkout->settlement()?->reason);
        $checkout->returnForHost();
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function cursorModes(): iterable
    {
        yield 'native buffered' => [false, true];
        yield 'native unbuffered' => [false, false];
        yield 'emulated buffered' => [true, true];
        yield 'emulated unbuffered' => [true, false];
    }

    #[DataProvider('cursorModes')]
    public function testRowBoundFailureClosesTheResultAndPreservesTheHostTransaction(bool $emulated, bool $buffered): void
    {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $pdo = $this->delegatedConnection();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
            'write' => new ProtectedTransactionCommandPlan("INSERT INTO `$table` (id, v) VALUES (1, 10)", []),
            'overflow' => new ProtectedTransactionCommandPlan('SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3', [], 1),
            'read' => new ProtectedTransactionCommandPlan("SELECT v FROM `$table` WHERE id = 1", [], 1),
        ]));
        unset($pdo);
        $checkout->begin();
        self::assertSame(1, $checkout->runRegisteredCommand('write'));
        try {
            $checkout->runRegisteredCommand('overflow');
            self::fail('Expected the row bound to reject the result.');
        } catch (ProtectedTransactionException $failure) {
            self::assertSame('The protected transaction result exceeds its row bound.', $failure->getMessage());
        }
        self::assertUnsettled($checkout);
        self::assertSame([['v' => 10]], $checkout->runRegisteredCommand('read'));
        self::assertSame([], $this->values($observer, $table));
        $checkout->commit();
        self::assertSame(ProtectedTransactionReasonEnum::Committed, $checkout->settlement()?->reason);
        self::assertSame([10], $this->values($observer, $table));
        $checkout->returnForHost();
    }

    /** @return iterable<string, array{bool, string, string, ProtectedTransactionReasonEnum}> */
    public static function completionLossModes(): iterable
    {
        yield 'commit acknowledgement lost' => [true, 'after', 'SERVER OK WITHHELD', ProtectedTransactionReasonEnum::CommitOutcomeUnknown];
        yield 'lost after commit acknowledgement' => [true, 'after-ack', 'SERVER OK FORWARDED', ProtectedTransactionReasonEnum::CommitConfirmedConnectionUnsafe];
        yield 'rollback acknowledgement lost' => [false, 'after', 'SERVER OK WITHHELD', ProtectedTransactionReasonEnum::RollbackUnconfirmed];
    }

    #[DataProvider('completionLossModes')]
    public function testCompletionOutcomeAfterConnectionLoss(
        bool $commit,
        string $phase,
        string $report,
        ProtectedTransactionReasonEnum $reason,
    ): void {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $relay = PartnerProcess::start([
            PHP_BINARY,
            dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php',
            $commit ? 'COMMIT AND NO CHAIN NO RELEASE' : 'ROLLBACK AND NO CHAIN NO RELEASE',
            $phase,
        ]);
        try {
            $ready = $relay->readLine(12.0);
            if (preg_match('/\AREADY (127\.0\.0\.1:[0-9]+)\z/', $ready, $endpoint) !== 1) {
                self::fail('The protected transaction test relay did not start.');
            }
            $pdo = new PDO(
                'mysql:host=' . str_replace(':', ';port=', $endpoint[1]) . ';dbname=' . self::setting('DATABASE'),
                self::setting('USER'),
                self::setting('PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8],
            );
            $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
                'insert' => new ProtectedTransactionCommandPlan("INSERT INTO `$table` (id, v) VALUES (1, 10)", []),
            ]));
            unset($pdo);
            $checkout->begin();
            $checkout->runRegisteredCommand('insert');
            self::failureOf(static fn() => $commit ? $checkout->commit() : $checkout->rollBack());
            self::assertSame($report, $relay->readLine(12.0));
            $observer->exec('SET SESSION innodb_lock_wait_timeout = 4');
            self::assertTrue($observer->beginTransaction());
            try {
                // Verify the entire one-row write set after the relay's
                // server END acknowledgement, even when the client lost it.
                $statement = $observer->query("SELECT v FROM `$table` WHERE id=1 FOR UPDATE");
                self::assertNotFalse($statement);
                self::assertSame($commit ? [10] : [], $statement->fetchAll(PDO::FETCH_COLUMN));
                self::assertTrue($statement->closeCursor());
            } finally {
                self::assertTrue($observer->rollBack());
            }
            self::assertSame($commit ? [10] : [], $this->values($observer, $table));
            $settlement = $checkout->settlement();
            self::assertSame($reason, $settlement?->reason);
            $this->reject(static fn() => $checkout->runRegisteredCommand('insert'));
            $this->reject(static fn() => $checkout->returnForHost());
            $checkout->discardForHost();
            self::assertSame($settlement, $checkout->settlement());
        } finally {
            $relay->stop();
        }
    }

    public function testStatementFailurePreservesOriginalDriverErrorAndFencesFurtherCommands(): void
    {
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($this->delegatedConnection(), new ProtectedTransactionCommandCatalog([
            'fail' => new ProtectedTransactionCommandPlan('SELECT no_such_column', [], 1),
            'read' => new ProtectedTransactionCommandPlan('SELECT 1 AS n', [], 1),
        ]));
        $checkout->begin();
        $previousIni = ini_set('zend.exception_ignore_args', '0');
        try {
            $failure = self::failureOf(static fn() => $checkout->runRegisteredCommand('fail'));
            self::assertSame(1054, self::driverCode($failure));
            foreach ($failure->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $arg) {
                    self::assertNotInstanceOf(PDO::class, $arg);
                    self::assertNotInstanceOf(PDOStatement::class, $arg);
                }
            }
        } finally {
            if ($previousIni !== false) {
                ini_set('zend.exception_ignore_args', $previousIni);
            }
        }
        self::assertSame(ProtectedTransactionDispositionEnum::DiscardRequired, $checkout->settlement()?->disposition);
        $this->reject(static fn() => $checkout->runRegisteredCommand('read'));
        $this->reject(static fn() => $checkout->commit());
        $checkout->discardForHost();
    }

    public function testHostCannotCallThePackageDriverBridge(): void
    {
        $checkout = PdoProtectedTransactionCheckout::attachExclusive(
            $this->connect(),
            new ProtectedTransactionCommandCatalog([]),
        );
        self::assertNotContains('runOrdering', get_class_methods($checkout));
        self::assertNotContains('run', get_class_methods($checkout));
        $checkout->returnForHost();
    }

    public function testRejectedAttachmentHidesPdoFromTheExceptionArguments(): void
    {
        $pdo = $this->connect();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $previousIni = ini_set('zend.exception_ignore_args', '0');
        try {
            PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
            self::fail('Expected the unqualified PDO to be rejected.');
        } catch (ProtectedTransactionException $failure) {
            foreach ($failure->getTrace() as $frame) {
                if ($frame['function'] === 'attachExclusive') {
                    self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0] ?? null);

                    return;
                }
            }
            self::fail('The attachment failure did not include its public API frame.');
        } finally {
            if ($previousIni !== false) {
                ini_set('zend.exception_ignore_args', $previousIni);
            }
        }
    }

    /** @return iterable<string, array{int, bool, ProtectedTransactionReasonEnum}> */
    public static function completionSettings(): iterable
    {
        yield 'CHAIN with commit' => [1, true, ProtectedTransactionReasonEnum::Committed];
        yield 'CHAIN with rollback' => [1, false, ProtectedTransactionReasonEnum::RolledBack];
        yield 'RELEASE with commit' => [2, true, ProtectedTransactionReasonEnum::Committed];
        yield 'RELEASE with rollback' => [2, false, ProtectedTransactionReasonEnum::RolledBack];
    }

    #[DataProvider('completionSettings')]
    public function testExplicitCompletionPreservesHostCompletionSetting(
        int $completion,
        bool $commit,
        ProtectedTransactionReasonEnum $reason,
    ): void {
        $pdo = $this->delegatedConnection();
        $pdo->exec('SET SESSION completion_type = ' . $completion);
        $id = $this->scalar($pdo, 'SELECT CONNECTION_ID()');
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        unset($pdo);
        $checkout->begin();
        $commit ? $checkout->commit() : $checkout->rollBack();
        self::assertSame($reason, $checkout->settlement()?->reason);
        $returned = $checkout->returnForHost();
        self::assertFalse($returned->inTransaction());
        self::assertSame($id, $this->scalar($returned, 'SELECT CONNECTION_ID()'));
        $setting = strtoupper($this->scalar($returned, 'SELECT @@completion_type'));
        self::assertContains($setting, [$completion === 1 ? 'CHAIN' : 'RELEASE', (string) $completion]);
    }

    public function testLockTimeoutFencesWithoutReplayingTheRegisteredCommand(): void
    {
        $owner = $this->connect();
        $table = $this->createTable($owner);
        $owner->exec("INSERT INTO `$table` (id, v) VALUES (1, 10)");
        $owner->beginTransaction();
        try {
            $owner->exec("UPDATE `$table` SET v = 20 WHERE id = 1");
            $pdo = $this->delegatedConnection();
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
                'write' => new ProtectedTransactionCommandPlan("UPDATE `$table` SET v = 30 WHERE id = 1", []),
            ]));
            unset($pdo);
            $checkout->begin();
            self::assertSame(1205, self::driverCode(self::failureOf(static fn() => $checkout->runRegisteredCommand('write'))));
            self::assertSame(ProtectedTransactionReasonEnum::OperationUnconfirmed, $checkout->settlement()?->reason);
            $this->reject(static fn() => $checkout->runRegisteredCommand('write'));
            $checkout->discardForHost();
        } finally {
            $owner->rollBack();
        }
        self::assertSame([10], $this->values($owner, $table));
    }

    public function testOnceReservedTheHostCannotRunAnotherOperationOrRetainTheDriver(): void
    {
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($this->connect(), new ProtectedTransactionCommandCatalog([
            'read' => new ProtectedTransactionCommandPlan('SELECT 1 AS n', [], 1),
        ]));
        $checkout->begin();
        $held = null;
        $operation = function (ProtectedTransactionConnection $scope) use ($checkout, &$held): bool {
            $held = $scope;
            $this->reject(static fn() => $checkout->runRegisteredCommand('read'));
            $this->reject(static fn() => $checkout->commit());
            $this->reject(static fn() => $checkout->discardForHost());
            self::assertUnsettled($checkout);
            $statement = $scope->prepare('SELECT 1');
            $statement->execute();

            return $statement->fetchColumn() === 1;
        };
        $reserve = Closure::bind(static fn(): bool => $checkout->run($operation), null, PdoProtectedTransactionCheckout::class);
        self::assertTrue($reserve());
        self::assertInstanceOf(ProtectedTransactionConnection::class, $held);
        $this->reject(static fn() => $held->prepare('SELECT 1'));
        self::assertSame([['n' => 1]], $checkout->runRegisteredCommand('read'));
        $checkout->rollBack();
        $checkout->returnForHost();
    }

    public function testUnacknowledgedStartCannotBecomeAnActiveCheckout(): void
    {
        $relay = PartnerProcess::start([
            PHP_BINARY,
            dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php',
            'START TRANSACTION READ WRITE',
            'after',
        ]);
        try {
            $ready = $relay->readLine(12.0);
            if (preg_match('/\AREADY 127\.0\.0\.1:([0-9]+)\z/', $ready, $endpoint) !== 1) {
                self::fail('The protected transaction test relay did not start.');
            }
            $pdo = new PDO(
                'mysql:host=127.0.0.1;port=' . $endpoint[1] . ';dbname=' . self::setting('DATABASE'),
                self::setting('USER'),
                self::setting('PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8],
            );
            $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
            unset($pdo);
            self::failureOf(static fn() => $checkout->begin());
            self::assertSame('SERVER OK WITHHELD', $relay->readLine(12.0));
            self::assertSame(ProtectedTransactionReasonEnum::StartUnconfirmed, $checkout->settlement()?->reason);
            $this->reject(static fn() => $checkout->commit());
            $this->reject(static fn() => $checkout->returnForHost());
            $checkout->discardForHost();
        } finally {
            $relay->stop();
        }
    }

    private function delegatedConnection(): PDO
    {
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');

        return new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private static function assertUnsettled(PdoProtectedTransactionCheckout $checkout): void
    {
        self::assertNull($checkout->settlement());
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
        } catch (ProtectedTransactionException) {
            return;
        }

        self::fail('Expected an illegal protected checkout operation to be rejected.');
    }
}
