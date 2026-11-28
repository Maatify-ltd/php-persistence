<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Closure;
use LogicException;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum;
use Maatify\PhpPersistence\Pagination\Exception\SnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\Repository\PdoSnapshotPaginator;
use Maatify\PhpPersistence\Tests\Support\Pagination\PaginationFixture;
use Maatify\PhpPersistence\Tests\Support\Pagination\SnapshotCursorStatement;
use Maatify\PhpPersistence\Tests\Support\Pagination\SnapshotHostCheckout;
use PDO;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

final class PdoSnapshotPaginatorFailureTest extends PaginationIntegrationTestCase
{
    public function testUnchangedReturnsToTheHostWithoutPublishingAnActiveTransaction(): void
    {
        [$writer, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($writer, $table);
        $reader = $this->connect();
        $reader->beginTransaction();
        try {
            $reader->exec('DELETE FROM ' . $table);
            $source = new SnapshotHostCheckout($reader);
            [$checkout, , $operation] = $source->checkout();
            self::assertInstanceOf(SnapshotPaginationException::class, self::failure(fn() => $operation->paginate(
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                static fn(array $row): array => $row,
            )));
            self::assertSame(SnapshotConnectionDispositionEnum::Unchanged, $operation->connectionOutcome()?->disposition);
            $source->settle($checkout, $operation->connectionOutcome());
            self::assertFalse($source->canCheckout());
            self::assertSame($reader, $source->takeReturnedConnection());
            self::assertTrue($reader->inTransaction());
            self::assertSame('0', $this->scalar($reader, 'SELECT COUNT(*) FROM ' . $table));
            self::assertSame('6', $this->scalar($writer, 'SELECT COUNT(*) FROM ' . $table));
        } finally {
            $reader->rollBack();
        }
    }

    public function testExecutionOfACompletedReadDoesNotPublishPageOnHostSettlementFailure(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source = new SnapshotHostCheckout($pdo);
        [$checkout, , $operation] = $source->checkout();
        $fault = new RuntimeException('Host rejected settlement');
        $escaped = self::failure(function () use ($operation, $table, $fault, $source, $checkout): PageResultDTO {
            $page = $operation->paginate($this->descriptor($table), new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::assertSame(3, $page->filtered);
            self::assertSame(SnapshotConnectionDispositionEnum::Reusable, $operation->connectionOutcome()?->disposition);
            $source->settle($checkout, $operation->connectionOutcome(), $fault);

            return $page;
        });
        self::assertSame($fault, $escaped);
        self::assertFalse($source->canCheckout());
    }

    public function testUnbufferedFetchFailureWithUnreadRowsStillAllowsConfirmedRollback(): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $reader = $this->unbufferedReader();
        $source = new SnapshotHostCheckout($reader);
        [$checkout, , $operation] = $source->checkout();
        $primary = new RuntimeException('fetch failed with unread wire rows');
        $reader->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            SnapshotCursorStatement::class,
            ([
                static fn(): bool => true,
                static function (string $sql) use ($primary): void {
                    if (str_contains($sql, 'SELECT id, tenant_id')) {
                        throw $primary;
                    }
                },
            ]),
        ]);
        try {
            self::assertSame($primary, self::failure(fn() => $operation->paginate(
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                static fn(array $row): array => $row,
            )));
            self::assertSame(SnapshotConnectionDispositionEnum::Reusable, $operation->connectionOutcome()?->disposition);
            $source->settle($checkout, $operation->connectionOutcome());
            [$next, $available] = $source->checkout();
            self::assertSame($reader, $available);
            self::assertSame('6', $this->scalar($available, 'SELECT COUNT(*) FROM ' . $table));
            $source->settle($next, null);
        } finally {
            self::endFixtureTransaction($reader);
        }
    }

    public function testSuccessfulPageDoesNotRequeueUntilHostSettles(): void
    {
        [$reader, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($reader, $table);
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source = new SnapshotHostCheckout($reader);
        [$checkout, $pdo, $operation] = $source->checkout();
        $page = $operation->paginate($this->descriptor($table), new PageRequest(), $this->config(), static fn(array $row): array => $row);
        self::assertSame(3, $page->filtered);
        self::assertFalse($source->canCheckout());

        $other = $this->connect();
        $other->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $foreign = PdoSnapshotPaginator::forExclusiveRead($other);
        $foreign->paginate($this->descriptor($table), new PageRequest(), $this->config(), static fn(array $row): array => $row);
        self::assertInstanceOf(LogicException::class, self::failure(static fn() => $source->settle($checkout, $foreign->connectionOutcome())));
        self::assertFalse($source->canCheckout(), 'an outcome from a different session cannot release this checkout');

        $source->settle($checkout, $operation->connectionOutcome());
        [$next, $sameConnection] = $source->checkout();
        self::assertSame($pdo, $sameConnection);
        self::assertSame('6', $this->scalar($sameConnection, 'SELECT COUNT(*) FROM ' . $table));
        $source->settle($next, null);
    }

    public function testChangedSessionDuringEligibilityCannotReturnUnchanged(): void
    {
        [, $table] = $this->connectWithPaginationTable();
        $replacement = $this->connect();
        $replacement->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $reader = new class (
            'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE'),
            self::setting('USER'),
            self::setting('PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ) extends PDO {
            public PDO $replacement;
            public bool $sessionChanged = false;
            /** @var list<string> */
            public array $controls = [];

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                if (str_starts_with($query, 'SHOW SESSION VARIABLES')) {
                    $this->sessionChanged = true;
                }
                if ($this->sessionChanged) {
                    return $this->replacement->query($query, $fetchMode, ...$fetchModeArgs);
                }

                return parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            public function exec(string $statement): int|false
            {
                $this->controls[] = $statement;

                return parent::exec($statement);
            }
        };
        $reader->replacement = $replacement;
        $source = new SnapshotHostCheckout($reader);
        [$checkout, , $operation] = $source->checkout();

        self::assertInstanceOf(SnapshotPaginationException::class, self::failure(fn() => $operation->paginate(
            $this->descriptor($table),
            new PageRequest(),
            $this->config(),
            static fn(): never => self::fail('The replacement session must not read a page.'),
        )));
        self::assertTrue($reader->sessionChanged);
        self::assertSame([], $reader->controls);
        $outcome = $operation->connectionOutcome();
        $source->settle($checkout, $outcome);
        self::assertSame(SnapshotConnectionDispositionEnum::Discard, $outcome?->disposition);
        self::assertNull($source->takeReturnedConnection());
        self::assertFalse($source->canCheckout());
    }

    public function testChangedSessionIdentityBeforeCommitForbidsTransactionControl(): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $reader = new class (
            'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE'),
            self::setting('USER'),
            self::setting('PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ) extends PDO {
            public bool $reportDifferentSession = false;

            /** @var list<string> */
            public array $controls = [];

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                if ($query === 'SELECT CONNECTION_ID()' && $this->reportDifferentSession) {
                    $query = 'SELECT CONNECTION_ID() + 1';
                }

                return parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            public function exec(string $statement): int|false
            {
                if (preg_match('/\A(START TRANSACTION|COMMIT|ROLLBACK)/', $statement) === 1) {
                    $this->controls[] = $statement;
                }

                return parent::exec($statement);
            }
        };
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source = new SnapshotHostCheckout($reader);
        [$checkout, $pdo, $operation] = $source->checkout();
        try {
            self::assertInstanceOf(SnapshotPaginationException::class, self::failure(fn() => $operation->paginate(
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                static function (array $row) use ($reader): array {
                    // Simulate a different session report while keeping a real transaction for observation.
                    $reader->reportDifferentSession = true;

                    return $row;
                },
            )));
            self::assertSame(['START TRANSACTION READ ONLY'], $reader->controls);
            self::assertSame(SnapshotConnectionDispositionEnum::Discard, $operation->connectionOutcome()?->disposition);
            $source->settle($checkout, $operation->connectionOutcome());
            self::assertFalse($source->canCheckout());
        } finally {
            self::endFixtureTransaction($reader);
        }
    }

    public function testTheHostCanReuseSameSessionAfterUnbufferedMapperError(): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $reader = $this->unbufferedReader();
        $session = $this->scalar($reader, 'SELECT CONNECTION_ID()');
        try {
            $reader->exec('SET SESSION completion_type = 1');
            $source = new SnapshotHostCheckout($reader);
            [$checkout, $pdo, $operation] = $source->checkout();
            $primary = new RuntimeException('mapper stopped the read');
            $failure = self::failure(fn() => $operation->paginate(
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                static fn(): never => throw $primary,
            ));
            self::assertSame($primary, $failure);
            self::assertFalse($source->canCheckout());
            $outcome = $operation->connectionOutcome();
            $source->settle($checkout, $outcome);

            self::assertSame(SnapshotConnectionDispositionEnum::Reusable, $outcome?->disposition);
            self::assertTrue($source->canCheckout());
            [$next, $available] = $source->checkout();
            self::assertSame($pdo, $available);
            self::assertFalse($available->inTransaction());
            self::assertSame($session, $this->scalar($available, 'SELECT CONNECTION_ID()'));
            self::assertSame('6', $this->scalar($available, 'SELECT COUNT(*) FROM ' . $table));
            self::assertInstanceOf(LogicException::class, self::failure(static fn() => $source->settle($checkout, $outcome)));
            self::assertInstanceOf(
                LogicException::class,
                self::failure(static fn() => $source->settle($next, $outcome)),
            );
            self::assertFalse($source->canCheckout(), 'a stale result cannot release the next checkout even with its current sequence');
            $source->settle($next, null);
        } finally {
            self::endFixtureTransaction($reader);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cursorFaults(): iterable
    {
        yield 'total count returns false' => ['AS total_count', false];
        yield 'filtered count throws' => ['AS filtered_count', true];
        yield 'data close returns false while mapper failure is retained' => ['SELECT id, tenant_id', false];
        yield 'data close throws while mapper failure is retained' => ['SELECT id, tenant_id', true];
    }

    #[DataProvider('cursorFaults')]
    public function testUnknownCursorReleaseDiscardsTheCheckout(string $needle, bool $throws): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $reader = $this->unbufferedReader();
        $readerId = (int) $this->scalar($reader, 'SELECT CONNECTION_ID()');
        $closeAttempts = 0;
        $closeFailure = new RuntimeException('cursor close failed');
        $reader->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            SnapshotCursorStatement::class,
            ([
                static function (string $sql) use ($needle, $throws, $closeFailure, &$closeAttempts): bool {
                    if (! str_contains($sql, $needle)) {
                        return true;
                    }
                    $closeAttempts++;
                    if ($throws) {
                        throw $closeFailure;
                    }

                    return false;
                },
            ]),
        ]);
        $source = new SnapshotHostCheckout($reader);
        [$checkout, $pdo, $operation] = $source->checkout();
        $primary = new RuntimeException('mapper stopped with unread rows');
        try {
            $failure = self::failure(fn() => $operation->paginate(
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                static fn(): never => throw $primary,
            ));
            $outcome = $operation->connectionOutcome();
            $source->settle($checkout, $outcome);
            if ($needle === 'SELECT id, tenant_id') {
                self::assertSame($primary, $failure);
            } elseif ($throws) {
                self::assertSame($closeFailure, $failure);
            } else {
                self::assertInstanceOf(SnapshotPaginationException::class, $failure);
            }
            self::assertSame(1, $closeAttempts);
            self::assertSame(SnapshotConnectionDispositionEnum::Discard, $outcome?->disposition);
            self::assertSame('cursor-release', $outcome->diagnostic);
            self::assertFalse($source->canCheckout());
        } finally {
            // The discarded connection may still own an unbuffered cursor.
            // Tear down the owned session through the separate fixture connection.
            $seed->exec('KILL CONNECTION ' . $readerId);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function prepareModes(): iterable
    {
        yield 'native prepare fails' => [false];
        yield 'emulated execution fails' => [true];
    }

    #[DataProvider('prepareModes')]
    public function testSqlFailureRollsBackWithoutChangingTheThrownPdoError(bool $emulate): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $reader = $this->unbufferedReader();
        $query = $this->descriptor($table);
        $reader->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        $invalid = new PdoPaginationQueryDescriptor(
            $query->totalSql,
            $query->totalParams,
            'SELECT COUNT(*) FROM deliberately_missing_c1_table',
            [],
            $query->dataSql,
            $query->dataParams,
        );
        $operation = PdoSnapshotPaginator::forExclusiveRead($reader);
        try {
            $failure = self::failure(fn() => $operation->paginate($invalid, new PageRequest(), $this->config(), static fn(array $row): array => $row));
            self::assertInstanceOf(PDOException::class, $failure);
            self::assertSame(1146, $failure->errorInfo[1] ?? null);
            self::assertSame(SnapshotConnectionDispositionEnum::Reusable, $operation->connectionOutcome()?->disposition);
            self::assertFalse($reader->inTransaction());
        } finally {
            self::endFixtureTransaction($reader);
        }
    }

    public function testHostHandoffFailureKeepsTheCheckoutUnavailableAndPreservesTheOriginalError(): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $reader = $this->unbufferedReader();
        $source = new SnapshotHostCheckout($reader);
        [$checkout, $pdo, $operation] = $source->checkout();
        $primary = new RuntimeException('page failure');
        $handoff = new RuntimeException('Host handoff failure');
        try {
            $escaped = self::failure(function () use ($operation, $table, $primary, $handoff, $source, $checkout): void {
                $read = self::failure(fn() => $operation->paginate($this->descriptor($table), new PageRequest(), $this->config(), static fn(): never => throw $primary));
                self::assertSame(SnapshotConnectionDispositionEnum::Reusable, $operation->connectionOutcome()?->disposition);
                $failure = self::failure(static fn() => $source->settle($checkout, $operation->connectionOutcome(), $handoff));
                self::assertSame($handoff, $failure);

                throw $read;
            });
            self::assertSame($primary, $escaped);
            self::assertFalse($source->canCheckout());
        } finally {
            self::endFixtureTransaction($reader);
        }
    }

    public function testRollbackControlFailureDiscardsWithoutReplacingMapperError(): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $reader = new class (
            'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE'),
            self::setting('USER'),
            self::setting('PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ) extends PDO {
            public int $rollbackAttempts = 0;

            public function exec(string $statement): int|false
            {
                if ($statement === 'ROLLBACK AND NO CHAIN NO RELEASE') {
                    $this->rollbackAttempts++;

                    return false;
                }

                return parent::exec($statement);
            }
        };
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source = new SnapshotHostCheckout($reader);
        [$checkout, $pdo, $operation] = $source->checkout();
        $primary = new RuntimeException('original mapper error');
        try {
            self::assertSame($primary, self::failure(fn() => $operation->paginate(
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                static fn(): never => throw $primary,
            )));
            $outcome = $operation->connectionOutcome();
            $source->settle($checkout, $outcome);
            self::assertSame(1, $reader->rollbackAttempts);
            self::assertSame(SnapshotConnectionDispositionEnum::Discard, $outcome?->disposition);
            self::assertSame('rollback', $outcome->diagnostic);
            self::assertFalse($source->canCheckout());
        } finally {
            self::endFixtureTransaction($reader);
        }
    }

    public function testHostDoesNotReleaseTheConnectionWhenATypeErrorPreventsAnOutcome(): void
    {
        $source = new SnapshotHostCheckout($this->connect());
        [$checkout, $pdo, $operation] = $source->checkout();
        $invoke = new \ReflectionMethod(PdoSnapshotPaginator::class, 'paginate');
        $failure = self::failure(fn() => $invoke->invoke($operation, null, new PageRequest(), $this->config(), static fn(array $row): array => $row));

        self::assertInstanceOf(\TypeError::class, $failure);
        self::assertNull($operation->connectionOutcome());
        $source->settle($checkout, null);
        self::assertFalse($source->canCheckout());
        self::assertFalse($pdo->inTransaction());
    }

    public function testHostRejectsAPendingOneShotPolicyBeforeDelegation(): void
    {
        [$writer, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($writer, $table);
        $reader = $this->connectInterleaving(static function (int $index, string $sql) use ($writer, $table): void {
            unset($index);
            if (str_contains($sql, 'AS filtered_count')) {
                (new PaginationFixture($writer, $table))->insertItem(1, 'book', 'Later', 10, true, null, '2026-01-07 00:00:00.000000');
            }
        });
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $reader->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');

        // The issuing Host knows that it queued this override. The PDO defaults alone cannot certify it.
        $source = new SnapshotHostCheckout($reader, knownClean: false);
        self::assertInstanceOf(LogicException::class, self::failure($source->checkout(...)));
        $reader->beginTransaction();
        try {
            $page = (new PdoPaginator())->paginate($reader, $this->descriptor($table), new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::assertSame(4, $page->total);
            self::assertSame(4, $page->filtered, 'the Host one-shot READ COMMITTED remains in effect');
            self::assertTrue($reader->inTransaction());
        } finally {
            $reader->rollBack();
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function lostServerAcknowledgments(): iterable
    {
        yield 'start reached the server' => ['START TRANSACTION READ ONLY', 'start', true];
        yield 'commit never reached the server' => ['COMMIT AND NO CHAIN NO RELEASE', 'commit', false];
        yield 'commit reached the server' => ['COMMIT AND NO CHAIN NO RELEASE', 'commit', true];
        yield 'rollback reached the server' => ['ROLLBACK AND NO CHAIN NO RELEASE', 'rollback', true];
    }

    #[DataProvider('lostServerAcknowledgments')]
    public function testHostDiscardsAfterServerAcknowledgementWasWithheld(string $control, string $phase, bool $forward): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $child = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', $control, $forward ? 'after' : 'before'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        );
        self::assertIsResource($child);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 12);
        try {
            $ready = fgets($pipes[1]);
            if (! is_string($ready) || preg_match('/\AREADY (127\.0\.0\.1:[0-9]+)\z/', trim($ready), $endpoint) !== 1) {
                self::fail('The loopback test relay did not report a valid endpoint.');
            }
            $reader = new PDO(
                'mysql:host=127.0.0.1;port=' . substr($endpoint[1], strlen('127.0.0.1:')) . ';dbname=' . self::setting('DATABASE'),
                self::setting('USER'),
                self::setting('PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 8],
            );
            $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source = new SnapshotHostCheckout($reader);
            [$checkout, $pdo, $operation] = $source->checkout();
            $mapperFailure = new RuntimeException('mapper failure before owned rollback');
            $mapper = $phase === 'rollback'
                ? static fn(): never => throw $mapperFailure
                : static fn(array $row): array => $row;
            $escaped = self::failure(fn() => $operation->paginate(
                $this->descriptor($table),
                new PageRequest(),
                $this->config(),
                $mapper,
            ));
            if ($phase === 'rollback') {
                self::assertSame($mapperFailure, $escaped);
            } else {
                self::assertInstanceOf(PDOException::class, $escaped);
            }
            self::assertSame($forward ? "SERVER OK WITHHELD\n" : "COMMAND NOT FORWARDED\n", fgets($pipes[1]));
            $outcome = $operation->connectionOutcome();
            self::assertSame(SnapshotConnectionDispositionEnum::Discard, $outcome?->disposition);
            self::assertSame($phase, $outcome->diagnostic);
            $source->settle($checkout, $outcome);
            self::assertFalse($source->canCheckout());
        } finally {
            fclose($pipes[1]);
            if (proc_get_status($child)['running']) {
                proc_terminate($child);
            }
            proc_close($child);
        }
    }

    private function unbufferedReader(): PDO
    {
        $pdo = $this->connectInterleaving(static function (): void {});
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);

        return $pdo;
    }

    private static function endFixtureTransaction(PDO $reader): void
    {
        if ($reader->inTransaction()) {
            // The fixture owns this PDO after disposition has been asserted.
            // The driver's rollBack bypasses the injected SQL-control failure.
            self::assertTrue($reader->rollBack());
        }
        self::assertFalse($reader->inTransaction());
    }

    private static function failure(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The operation must fail.');
    }
}
