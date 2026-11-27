<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\FaultInjectingPdo;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\SavepointScope;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Characterizes current Transaction outcomes with independent visibility on
 * InnoDB and deliberate failures at the PDO control boundary.
 */
final class TransactionOutcomeIntegrationTest extends IntegrationTestCase
{
    /** @return iterable<string, array{PDOException|false}> */
    public static function rollbackFailures(): iterable
    {
        yield 'false' => [false];
        yield 'throw' => [new PDOException('Injected rollBack failure.')];
    }

    #[DataProvider('rollbackFailures')]
    public function testOwnedRollbackFailureKeepsThePrimaryErrorAndLeavesTheWritePending(PDOException|false $fault): void
    {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $writer = $this->faultInjectingConnection();
        $writer->failNextRollback($fault);
        $failure = new RuntimeException('Operation rejected.');

        try {
            $thrown = $this->captureThrowable(static fn() => (new PdoTransactionRunner($writer))->run(
                static function () use ($writer, $table, $failure): never {
                    $writer->exec("INSERT INTO `$table` (id, v) VALUES (1, 10)");

                    throw $failure;
                },
            ));

            self::assertSame($failure, $thrown);
            self::assertTrue($writer->inTransaction());
            self::assertSame([10], $this->values($writer, $table));
            self::assertSame([], $this->values($observer, $table));
            self::assertTrue($writer->rollBack(), 'The test Host explicitly rolls back the pending write.');
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }

        self::assertSame([], $this->values($observer, $table));
    }

    /** @return iterable<string, array{string, 'false'|'throw', list<int>}> */
    public static function savepointCleanupFailures(): iterable
    {
        yield 'rollback-to false leaves operation pending' => ['ROLLBACK TO SAVEPOINT', 'false', [10, 20]];
        yield 'release throws after local undo' => ['RELEASE SAVEPOINT', 'throw', [10]];
    }

    /**
     * @param 'false'|'throw' $mode
     * @param list<int> $expectedPending
     */
    #[DataProvider('savepointCleanupFailures')]
    public function testTheSameCallbackErrorCanEscapeWithDifferentPendingWrites(
        string $control,
        string $mode,
        array $expectedPending,
    ): void {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $host = $this->faultInjectingConnection();
        $host->failExec('/\A' . $control . ' /', $mode);
        $sentinel = new RuntimeException('Operation rejected.');
        self::assertTrue($host->beginTransaction());

        try {
            $host->exec("INSERT INTO `$table` (id, v) VALUES (1, 10)");
            $thrown = $this->captureThrowable(static fn() => (new PdoSavepointTransactionRunner($host))->run(
                static function () use ($host, $table, $sentinel): never {
                    $host->exec("INSERT INTO `$table` (id, v) VALUES (2, 20)");

                    throw $sentinel;
                },
            ));

            self::assertSame($sentinel, $thrown);
            self::assertTrue($host->inTransaction());
            self::assertSame($expectedPending, $this->values($host, $table));
            self::assertSame([], $this->values($observer, $table));
            self::assertTrue($host->commit(), 'The test Host decides whether to commit the surrounding transaction.');
        } finally {
            if ($host->inTransaction()) {
                $host->rollBack();
            }
        }

        self::assertSame($expectedPending, $this->values($observer, $table));
    }

    /** @return iterable<string, array{bool}> */
    public static function releaseOutcomes(): iterable
    {
        yield 'release succeeds' => [true];
        yield 'release throws before dispatch' => [false];
    }

    #[DataProvider('releaseOutcomes')]
    public function testRawUndoRequiresBothRollbackAndReleaseAcknowledgements(bool $releaseSucceeds): void
    {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $host = $this->faultInjectingConnection();
        if (!$releaseSucceeds) {
            $host->failExec('/\ARELEASE SAVEPOINT /', 'throw');
        }
        self::assertTrue($host->beginTransaction());

        try {
            $host->exec("INSERT INTO `$table` (id, v) VALUES (1, 10)");
            $scope = SavepointScope::begin($host);
            $host->exec("INSERT INTO `$table` (id, v) VALUES (2, 20)");

            if ($releaseSucceeds) {
                $scope->undoOrFail();
            } else {
                $thrown = $this->captureThrowable(static fn() => $scope->undoOrFail());
                self::assertInstanceOf(PDOException::class, $thrown);
                self::assertSame('Injected control statement failure.', $thrown->getMessage());
            }

            self::assertTrue($host->inTransaction());
            self::assertSame([10], $this->values($host, $table));
            self::assertSame([], $this->values($observer, $table));
            $statements = $host->savepointStatements();
            self::assertCount(3, $statements);
            self::assertMatchesRegularExpression('/\ARELEASE SAVEPOINT maatify_persistence_sp_[0-9a-f]{32}\z/', $statements[2]);

            // query() sends the same release to the server without the exec() fault.
            if ($releaseSucceeds) {
                $missing = self::failureOf(static fn() => $host->query($statements[2]));
                self::assertSame(1305, self::driverCode($missing));
            } else {
                $release = $host->query($statements[2]);
                self::assertInstanceOf(PDOStatement::class, $release);
                self::assertTrue($release->closeCursor());
            }

            self::assertTrue($host->commit());
        } finally {
            if ($host->inTransaction()) {
                $host->rollBack();
            }
        }

        self::assertSame([10], $this->values($observer, $table));
    }

    /** @return iterable<string, array{string, string, list<int>}> */
    public static function lostCommitModes(): iterable
    {
        yield 'commit not forwarded' => ['before', 'COMMAND NOT FORWARDED', []];
        yield 'commit acknowledged only to relay' => ['after', 'SERVER OK WITHHELD', [10]];
    }

    /** @param list<int> $committed */
    #[DataProvider('lostCommitModes')]
    public function testOwnedCommitWithNoReplyThrowsRegardlessOfTheObservedOutcome(
        string $phase,
        string $relayEvidence,
        array $committed,
    ): void {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $relay = PartnerProcess::start([PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'COMMIT', $phase]);

        try {
            $writer = $this->relayedConnection($relay);
            $calls = 0;
            $callback = static function () use ($writer, $table, &$calls): string {
                ++$calls;
                $writer->exec("INSERT INTO `$table` (id, v) VALUES (1, 10)");

                return 'apply';
            };
            $failure = self::failureOf(static fn() => (new PdoTransactionRunner($writer, 3))->run($callback));

            self::assertContains(self::driverCode($failure), [2006, 2013]);
            self::assertSame(1, $calls);
            self::assertSame($relayEvidence, $relay->readLine(12.0));
            self::assertSame($committed, $this->values($observer, $table));
            self::failureOf(static fn() => $writer->query('SELECT 1'));
        } finally {
            $relay->stop();
        }
    }

    public function testOwnedRollbackWithNoReplyKeepsTheCallbackThrowableOnABrokenConnection(): void
    {
        $observer = $this->connect();
        $table = $this->createTable($observer);
        $relay = PartnerProcess::start([PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', 'ROLLBACK', 'after']);
        $failure = new RuntimeException('Operation rejected.');

        try {
            $writer = $this->relayedConnection($relay);
            $thrown = $this->captureThrowable(static fn() => (new PdoTransactionRunner($writer))->run(
                static function () use ($writer, $table, $failure): never {
                    $writer->exec("INSERT INTO `$table` (id, v) VALUES (1, 10)");

                    throw $failure;
                },
            ));

            self::assertSame($failure, $thrown);
            self::assertSame('SERVER OK WITHHELD', $relay->readLine(12.0));
            self::assertSame([], $this->values($observer, $table));
            self::failureOf(static fn() => $writer->query('SELECT 1'));
        } finally {
            $relay->stop();
        }
    }

    private function faultInjectingConnection(): FaultInjectingPdo
    {
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');

        return new FaultInjectingPdo($dsn, self::setting('USER'), self::setting('PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function relayedConnection(PartnerProcess $relay): PDO
    {
        $ready = $relay->readLine(12.0);
        if (preg_match('/\AREADY 127\.0\.0\.1:([0-9]+)\z/', $ready, $endpoint) !== 1) {
            self::fail('The transaction control relay did not start.');
        }
        $dsn = 'mysql:host=127.0.0.1;port=' . $endpoint[1] . ';dbname=' . self::setting('DATABASE');

        return new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
    }

    /** @param callable(): mixed $operation */
    private function captureThrowable(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('Expected a Throwable.');
    }
}
