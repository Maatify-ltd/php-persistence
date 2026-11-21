<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;

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
            $ready = $relay->readLine(12.0);
            if (preg_match('/\AREADY 127\.0\.0\.1:([0-9]+)\z/', $ready, $endpoint) !== 1) {
                self::fail('The protected ordering test relay did not start.');
            }
            $checkout = PdoProtectedTransactionCheckout::attachExclusive(
                $this->delegatedConnection($endpoint[1], true),
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
