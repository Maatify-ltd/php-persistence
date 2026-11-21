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
use PDO;
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
        $this->reject(static fn () => PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog));
        $this->reject(static fn () => $checkout->commit());
        self::assertSame($pdo, $checkout->returnForHost());
        self::assertSame(ProtectedTransactionDispositionEnum::UNCHANGED, $checkout->settlement()?->disposition);
        $this->reject(static fn () => $checkout->begin());
        $this->reject(static fn () => $checkout->returnForHost());
        $new = PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog);
        self::assertSame($pdo, $new->returnForHost());
    }

    public function testHostChoosesTransactionEndAndReceivesSameConnection(): void
    {
        $pdo = $this->connect();
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        $checkout->begin();
        self::assertTrue($pdo->inTransaction());
        self::assertUnsettled($checkout);
        $this->reject(static fn () => $checkout->returnForHost());
        $checkout->rollBack();
        self::assertFalse($pdo->inTransaction());
        $settlement = $checkout->settlement();
        self::assertSame(ProtectedTransactionDispositionEnum::REUSABLE, $settlement?->disposition);
        self::assertSame($pdo, $checkout->returnForHost());
        self::assertSame($settlement, $checkout->settlement());
    }

    public function testFailedServerQualificationFencesEvenAnUnstartedCheckout(): void
    {
        $pdo = $this->connect();
        $pdo->exec('SET SESSION autocommit = 0');
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        self::assertUnsettled($checkout);
        $this->reject(static fn () => $checkout->begin());
        $settlement = $checkout->settlement();
        self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $settlement?->disposition);
        $this->reject(static fn () => $checkout->returnForHost());
        $this->reject(static fn () => $checkout->rollBack());
        $checkout->discardForHost();
        self::assertSame($settlement, $checkout->settlement());
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
        $this->reject(static fn () => $checkout->runRegisteredCommand('insert'));
        self::assertSame(1, $checkout->runRegisteredCommand('insert', ['v' => 10]));
        self::assertSame([['v' => 10]], $checkout->runRegisteredCommand('read'));
        self::assertSame([], $this->values($observer, $table));
        $checkout->commit();
        self::assertSame([10], $this->values($observer, $table));
        self::assertSame(ProtectedTransactionReasonEnum::COMMITTED, $checkout->settlement()?->reason);
        $checkout->returnForHost();
    }

    /** @return iterable<string, array{string, string, ProtectedTransactionReasonEnum}> */
    public static function commitLossModes(): iterable
    {
        yield 'acknowledgement lost' => ['after', 'SERVER OK WITHHELD', ProtectedTransactionReasonEnum::COMMIT_OUTCOME_UNKNOWN];
        yield 'lost after acknowledgement' => ['after-ack', 'SERVER OK FORWARDED', ProtectedTransactionReasonEnum::COMMIT_CONFIRMED_CONNECTION_UNSAFE];
    }

    #[DataProvider('commitLossModes')]
    public function testCommitOutcomeAfterConnectionLoss(string $phase, string $report, ProtectedTransactionReasonEnum $reason): void
    {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $relay = PartnerProcess::start([
            PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'COMMIT AND NO CHAIN NO RELEASE', $phase,
        ]);
        try {
            $ready = $relay->readLine(12.0);
            if (preg_match('/\AREADY (127\.0\.0\.1:[0-9]+)\z/', $ready, $endpoint) !== 1) {
                self::fail('The protected transaction test relay did not start.');
            }
            $pdo = new PDO(
                'mysql:host=' . str_replace(':', ';port=', $endpoint[1]) . ';dbname=' . self::setting('DATABASE'),
                self::setting('USER'), self::setting('PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8],
            );
            $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
                'insert' => new ProtectedTransactionCommandPlan("INSERT INTO `$table` (id, v) VALUES (1, 10)", []),
            ]));
            unset($pdo);
            $checkout->begin();
            $checkout->runRegisteredCommand('insert');
            self::failureOf(static fn () => $checkout->commit());
            self::assertSame($report, $relay->readLine(12.0));
            self::assertSame([10], $this->values($observer, $table));
            $settlement = $checkout->settlement();
            self::assertSame($reason, $settlement?->reason);
            $this->reject(static fn () => $checkout->runRegisteredCommand('insert'));
            $this->reject(static fn () => $checkout->returnForHost());
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
            $failure = self::failureOf(static fn () => $checkout->runRegisteredCommand('fail'));
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
        self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $checkout->settlement()?->disposition);
        $this->reject(static fn () => $checkout->runRegisteredCommand('read'));
        $this->reject(static fn () => $checkout->commit());
        $checkout->discardForHost();
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
