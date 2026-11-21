<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Closure;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use Maatify\PhpPersistence\Transaction\Repository\SavepointScope;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

final class ProtectedScopedOrderingManagerIntegrationTest extends OrderingIntegrationTestCase
{
    /** @return iterable<string, array{bool, bool, bool}> */
    public static function hazardCases(): iterable
    {
        foreach ([false, true] as $snapshot) {
            foreach ([false, true] as $emulated) {
                yield 'allocation snapshot=' . (int) $snapshot . ' emulated=' . (int) $emulated => [$snapshot, $emulated, false];
                yield 'movement snapshot=' . (int) $snapshot . ' emulated=' . (int) $emulated => [$snapshot, $emulated, true];
            }
        }
    }

    #[DataProvider('hazardCases')]
    public function testGuardedOrderingFencesSnapshotHazard(bool $snapshot, bool $emulated, bool $move): void
    {
        $table = $this->scopedTable;
        $id = $this->fixture->insertScoped('target', 1);
        $pdo = $this->delegatedConnection(self::setting('PORT'), $emulated);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if (self::engine() === 'mariadb') {
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . ($snapshot ? 'ON' : 'OFF'));
        }
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
            'snapshot' => new ProtectedTransactionCommandPlan("SELECT COUNT(*) AS n FROM `$table`", [], 1),
            'write' => new ProtectedTransactionCommandPlan(
                "INSERT INTO `$table` (scope_key, display_order, label) VALUES ('host', 1, 'host')",
                [],
            ),
        ]));
        unset($pdo);
        $manager = new ProtectedScopedOrderingManager();
        $checkout->begin();
        $checkout->runRegisteredCommand('snapshot');
        $checkout->runRegisteredCommand('write');
        $this->fixture->insertScoped('target', 2);
        $operation = $move
            ? fn() => $manager->moveWithinScope($checkout, $this->scopedConfig(), 'target', $id, 2)
            : fn() => $manager->getNextPositionForUpdate($checkout, $this->scopedConfig(), 'target');
        if (!($snapshot && self::engine() === 'mariadb')) {
            self::assertSame($move ? true : 3, $operation());
            self::assertSame('0', $this->scalar($this->pdo, "SELECT COUNT(*) FROM `$table` WHERE scope_key = 'host'"));
            $checkout->commit();
            self::assertSame('1', $this->scalar($this->pdo, "SELECT COUNT(*) FROM `$table` WHERE scope_key = 'host'"));
            $checkout->returnForHost();

            return;
        }

        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $failure = self::failureOf($operation);
            self::assertSame(1020, self::driverCode($failure));
            foreach ($failure->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $arg) {
                    self::assertNotInstanceOf(PDO::class, $arg);
                    self::assertNotInstanceOf(PDOStatement::class, $arg);
                }
            }
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
        $settlement = $checkout->settlement();
        self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $settlement?->disposition);
        self::assertSame(ProtectedTransactionReasonEnum::TRANSACTION_LOST, $settlement->reason);
        $this->reject(static fn() => $checkout->runRegisteredCommand('write'));
        $this->reject(static fn() => $checkout->rollBack());
        $this->reject(static fn() => $checkout->returnForHost());
        self::assertSame('0', $this->scalar($this->pdo, "SELECT COUNT(*) FROM `$table` WHERE scope_key = 'host'"));
        $checkout->discardForHost();
        self::assertSame($settlement, $checkout->settlement());
    }

    public function testReleaseFailureSuppressesFalseAfterSuccessfulSavepointUndo(): void
    {
        $this->fixture->insertScoped('target', 1);
        $id = $this->fixture->insertScoped('target', 2);
        $relay = PartnerProcess::start([
            PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'RELEASE SAVEPOINT', 'ordering-release-error',
        ]);
        try {
            $checkout = PdoProtectedTransactionCheckout::attachExclusive(
                $this->delegatedConnection($this->relayPort($relay), true),
                new ProtectedTransactionCommandCatalog([]),
            );
            $checkout->begin();
            $manager = new ProtectedScopedOrderingManager();
            $error = self::failureOf(fn() => $manager->moveWithinScope($checkout, $this->scopedConfig(), 'target', $id, 1));
            self::assertSame(1305, self::driverCode($error));
            self::assertSame('TARGET UPDATE MADE EMPTY', $relay->readLine(12.0));
            self::assertSame('RELEASE REPLACED AFTER UNDO', $relay->readLine(12.0));
            self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $checkout->settlement()?->disposition);
            $this->reject(static fn() => $checkout->commit());
            $checkout->discardForHost();
        } finally {
            $relay->stop();
        }
    }

    public function testDriverErrorAfterSavepointFencesCleanupAndSubsequentCommands(): void
    {
        $first = $this->fixture->insertScoped('target', 1);
        $second = $this->fixture->insertScoped('target', 2);
        $relay = PartnerProcess::start([
            PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'RELEASE SAVEPOINT', 'ordering-1020',
        ]);
        try {
            $checkout = PdoProtectedTransactionCheckout::attachExclusive(
                $this->delegatedConnection($this->relayPort($relay), true),
                new ProtectedTransactionCommandCatalog(['read' => new ProtectedTransactionCommandPlan('SELECT 1', [], 1)]),
            );
            $checkout->begin();
            $manager = new ProtectedScopedOrderingManager();
            $failure = self::failureOf(fn() => $manager->moveWithinScope($checkout, $this->scopedConfig(), 'target', $second, 1));
            self::assertSame(1020, self::driverCode($failure));
            $settlement = $checkout->settlement();
            self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $settlement?->disposition);
            self::assertSame(
                self::engine() === 'mariadb' ? ProtectedTransactionReasonEnum::TRANSACTION_LOST : ProtectedTransactionReasonEnum::OPERATION_UNCONFIRMED,
                $settlement->reason,
            );
            $this->reject(static fn() => $checkout->runRegisteredCommand('read'));
            $this->reject(static fn() => $checkout->rollBack());
            self::assertSame('NO SQL AFTER DRIVER ERROR', $relay->readLine(12.0));
            self::assertSame([$first => 1, $second => 2], $this->reader()->scopedOrdersById('target'));
            $checkout->discardForHost();
            self::assertSame($settlement, $checkout->settlement());
        } finally {
            $relay->stop();
        }
    }

    public function testReleaseFailureKeepsEarlierMovementFailureAndSealsCheckout(): void
    {
        $relay = PartnerProcess::start([
            PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'RELEASE SAVEPOINT', 'ordering-release-error',
        ]);
        try {
            $checkout = PdoProtectedTransactionCheckout::attachExclusive(
                $this->delegatedConnection($this->relayPort($relay), true),
                new ProtectedTransactionCommandCatalog([]),
            );
            $checkout->begin();
            $primary = new RuntimeException('The movement failed locally.');
            $exercise = Closure::bind(static fn() => $checkout->run(
                static function (ProtectedTransactionConnection $connection) use ($primary): never {
                    $savepoint = SavepointScope::begin($connection);
                    try {
                        throw $primary;
                    } catch (RuntimeException $failure) {
                        $savepoint->cleanupAfterFailure();
                        throw $failure;
                    }
                },
            ), null, PdoProtectedTransactionCheckout::class);
            self::assertSame($primary, $this->caughtFailure($exercise));
            self::assertSame('RELEASE REPLACED AFTER UNDO', $relay->readLine(12.0));
            self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $checkout->settlement()?->disposition);
            $this->reject(static fn() => $checkout->returnForHost());
            $checkout->discardForHost();
        } finally {
            $relay->stop();
        }
    }

    public function testProtectedTailExhaustionLeavesTheHostInControlOfTheActiveTransaction(): void
    {
        $table = $this->scopedTable;
        $this->fixture->insertScoped('target', 1);
        $this->fixture->insertScoped('target', 2);
        $relay = PartnerProcess::start([
            PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'RELEASE SAVEPOINT', 'protected-tail-churn', $table,
        ]);
        try {
            $pdo = $this->delegatedConnection($this->relayPort($relay), true);
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->arrangeLatestCommittedStateGuarantee($pdo);
            $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
                'write' => new ProtectedTransactionCommandPlan(
                    "INSERT INTO `$table` (scope_key, display_order, label) VALUES ('host', 1, 'host')",
                    [],
                ),
            ]));
            unset($pdo);
            $checkout->begin();
            self::assertSame(1, $checkout->runRegisteredCommand('write'));
            $manager = new ProtectedScopedOrderingManager();
            try {
                $manager->getNextPositionForUpdate($checkout, $this->scopedConfig(), 'target', 2);
                self::fail('Expected the protected tail read to exhaust its budget.');
            } catch (OrderingExecutionException $failure) {
                self::assertSame(['reason' => 'TAIL_READ_LIMIT_EXCEEDED', 'maxTailReads' => 2], $failure->getMeta());
            }
            self::assertSame('TAIL ADVANCED', $relay->readLine(12.0));
            self::assertNull($checkout->settlement());
            self::assertSame('0', $this->scalar($this->pdo, "SELECT COUNT(*) FROM `$table` WHERE scope_key = 'host'"));
            $checkout->commit();
            self::assertSame(ProtectedTransactionReasonEnum::COMMITTED, $checkout->settlement()?->reason);
            self::assertSame('1', $this->scalar($this->pdo, "SELECT COUNT(*) FROM `$table` WHERE scope_key = 'host'"));
            $checkout->returnForHost();
        } finally {
            $relay->stop();
        }
    }

    private function relayPort(PartnerProcess $relay): string
    {
        if (preg_match('/\AREADY 127\.0\.0\.1:([0-9]+)\z/', $relay->readLine(12.0), $endpoint) !== 1) {
            self::fail('The protected ordering test relay did not start.');
        }

        return $endpoint[1];
    }

    private function caughtFailure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('Expected the original movement failure.');
    }

    private function delegatedConnection(string $port, bool $emulated): PDO
    {
        return new PDO(
            'mysql:host=127.0.0.1;port=' . $port . ';dbname=' . self::setting('DATABASE'),
            self::setting('USER'),
            self::setting('PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => $emulated, PDO::ATTR_TIMEOUT => 8, Mysql::ATTR_USE_BUFFERED_QUERY => false],
        );
    }

    private function reject(callable $action): void
    {
        try {
            $action();
        } catch (ProtectedTransactionException) {
            return;
        }

        self::fail('Expected an invalidated checkout to reject the operation.');
    }
}
