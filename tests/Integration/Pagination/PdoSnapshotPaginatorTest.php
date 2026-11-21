<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum;
use Maatify\PhpPersistence\Pagination\Exception\SnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\Repository\PdoSnapshotPaginator;
use Maatify\PhpPersistence\Tests\Support\Pagination\PaginationFixture;
use Error;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

final class PdoSnapshotPaginatorTest extends PaginationIntegrationTestCase
{
    /** @return iterable<string, array{string}> */
    public static function concurrentWrites(): iterable
    {
        yield 'insert between counts' => ['insert'];
        yield 'update between filtered count and data' => ['update'];
        yield 'delete between filtered count and data' => ['delete'];
    }

    /** @param 'insert'|'update'|'delete' $operation */
    #[DataProvider('concurrentWrites')]
    public function testCountsAndRowsUseOneSnapshot(string $operation): void
    {
        [$writer, $table] = $this->connectWithPaginationTable();
        $original = $this->insertMultiTenantDataset($writer, $table);
        $mutationRan = false;
        $reader = $this->connectInterleaving(
            function (int $index, string $sql) use ($operation, $writer, $table, $original, &$mutationRan): void {
                unset($index);
                $boundary = $operation === 'insert' ? 'AS filtered_count' : 'SELECT id, tenant_id';
                if (! $mutationRan && str_contains($sql, $boundary)) {
                    $mutationRan = true;
                    match ($operation) {
                        'insert' => (new PaginationFixture($writer, $table))->insertItem(
                            1,
                            'book',
                            'Concurrent',
                            40,
                            true,
                            null,
                            '2026-01-04 00:00:00.000000',
                        ),
                        'update' => $writer->exec('UPDATE ' . $table . ' SET is_active=0 WHERE id=' . $original[1]),
                        'delete' => $writer->exec('DELETE FROM ' . $table . ' WHERE id=' . $original[1]),
                    };
                }
            },
        );
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $reader->exec('SET SESSION completion_type = 2');
        $request = new PageRequest(1, 10, 'id', 'ASC');
        $query = $this->descriptor($table);
        $config = $this->config();
        $mapper = static fn(array $row): array => $row;

        $snapshot = PdoSnapshotPaginator::forExclusiveRead($reader);
        $result = $snapshot->paginate($query, $request, $config, $mapper);
        $outcome = $snapshot->connectionOutcome();
        self::assertNotNull($outcome);
        self::assertSame(SnapshotConnectionDispositionEnum::REUSABLE, $outcome->disposition);
        self::assertSame($outcome, $snapshot->connectionOutcome());
        self::assertTrue($mutationRan);
        self::assertSame(4, $result->total);
        self::assertSame(3, $result->filtered);
        self::assertSame(array_slice($original, 0, 3), array_column($result->data, 'id'));
        self::assertFalse($reader->inTransaction());
        self::assertContains(
            $this->scalar($reader, 'SELECT @@SESSION.completion_type'),
            ['2', 'RELEASE'],
        );
        $fresh = (new PdoPaginator())->paginate($reader, $query, $request, $config, $mapper);
        self::assertSame($operation === 'insert' ? 4 : 2, $fresh->filtered);
    }

    /** @return iterable<string, array{string}> */
    public static function ineligibleConnections(): iterable
    {
        yield 'Host transaction' => ['active'];
        yield 'implicit transaction policy' => ['implicit'];
        yield 'default isolation' => ['isolation'];
        yield 'error mode' => ['errmode'];
    }

    /** @param 'active'|'implicit'|'isolation'|'errmode' $case */
    #[DataProvider('ineligibleConnections')]
    public function testRejectsIneligibleConnectionWithoutManagingHostTransaction(string $case): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        $reader = $this->connect();
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        match ($case) {
            'active' => $reader->beginTransaction(),
            'implicit' => $reader->exec('SET SESSION autocommit = 0'),
            'isolation' => $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'),
            'errmode' => $reader->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT),
        };
        if ($case === 'active') {
            $reader->exec('DELETE FROM ' . $table);
        }

        try {
            $snapshot = PdoSnapshotPaginator::forExclusiveRead($reader);
            try {
                $snapshot->paginate(
                    $this->descriptor($table),
                    new PageRequest(),
                    $this->config(),
                    static fn(): never => self::fail('An ineligible connection must not read the page.'),
                );
                self::fail('An ineligible connection must be rejected.');
            } catch (SnapshotPaginationException) {
                self::assertSame(SnapshotConnectionDispositionEnum::UNCHANGED, $snapshot->connectionOutcome()?->disposition);
            }
            self::assertSame($case === 'active', $reader->inTransaction());
            if ($case === 'active') {
                self::assertSame('0', $this->scalar($reader, 'SELECT COUNT(*) FROM ' . $table));
                self::assertSame('6', $this->scalar($seed, 'SELECT COUNT(*) FROM ' . $table));
            } elseif ($case === 'implicit') {
                self::assertSame('0', $this->scalar($reader, 'SELECT @@SESSION.autocommit'));
            } elseif ($case === 'errmode') {
                self::assertSame(PDO::ERRMODE_SILENT, $reader->getAttribute(PDO::ATTR_ERRMODE));
            }
        } finally {
            if ($reader->inTransaction()) {
                $reader->rollBack();
            }
            if ($case === 'implicit') {
                $reader->exec('SET SESSION autocommit = 1');
            }
        }
    }

    public function testOneShotOperationPreservesOutcomeAcrossReentryAndRepeat(): void
    {
        [$reader, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($reader, $table);
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $snapshot = PdoSnapshotPaginator::forExclusiveRead($reader);
        $query = $this->descriptor($table);
        $request = new PageRequest(999, 1, 'id', 'ASC');
        $config = $this->config();

        $page = $snapshot->paginate($query, $request, $config, static function (array $row) use ($snapshot, $query, $request, $config): array {
            self::assertNull($snapshot->connectionOutcome());
            try {
                $snapshot->paginate($query, $request, $config, static fn(array $item): array => $item);
                self::fail('Reentrant pagination must be rejected.');
            } catch (SnapshotPaginationException) {
                self::assertNull($snapshot->connectionOutcome());
            }

            return $row;
        });
        self::assertSame(1, $page->page);
        self::assertCount(1, $page->data);
        $outcome = $snapshot->connectionOutcome();
        self::assertSame(SnapshotConnectionDispositionEnum::REUSABLE, $outcome?->disposition);
        try {
            $snapshot->paginate($query, $request, $config, static fn(array $row): array => $row);
            self::fail('The lease must not be consumed twice.');
        } catch (SnapshotPaginationException) {
            self::assertSame($outcome, $snapshot->connectionOutcome());
        }

        $this->expectException(SnapshotPaginationException::class);
        serialize($snapshot);
    }

    public function testSnapshotHandleCannotBeRestoredFromSerializedData(): void
    {
        $class = PdoSnapshotPaginator::class;

        $this->expectException(SnapshotPaginationException::class);
        unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class), ['allowed_classes' => [$class]]);
    }

    public function testRegisteringSnapshotDoesNotInspectTheHandle(): void
    {
        // An uninitialised PDO would reject a driver or SQL call.
        $pdo = new class extends PDO {
            public function __construct() {}
        };
        $snapshot = PdoSnapshotPaginator::forExclusiveRead($pdo);
        self::assertNull($snapshot->connectionOutcome());
        $this->expectException(Error::class);
        $this->expectExceptionMessage('__clone');
        $copy = clone ($snapshot);
    }

    public function testEligibilityUncertaintyDoesNotCertifyAnUnchangedConnection(): void
    {
        $pdo = new class extends PDO {
            public function __construct() {}

            public function getAttribute(int $attribute): mixed
            {
                unset($attribute);

                return false;
            }
        };
        $snapshot = PdoSnapshotPaginator::forExclusiveRead($pdo);
        try {
            $snapshot->paginate($this->descriptor('unused'), new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Unavailable eligibility information must not be accepted.');
        } catch (SnapshotPaginationException) {
            // The reader never ran. The Host must discard the uncertain checkout.
        }
        self::assertSame(SnapshotConnectionDispositionEnum::DISCARD, $snapshot->connectionOutcome()?->disposition);
    }

    public function testServerAutocommitWinsOverTheCachedPdoAttributeInBothDirections(): void
    {
        [$seed, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($seed, $table);
        foreach ([false, true] as $serverAutocommit) {
            $reader = $this->connect();
            $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $reader->setAttribute(PDO::ATTR_AUTOCOMMIT, ! $serverAutocommit);
            $reader->exec('SET SESSION autocommit = ' . ($serverAutocommit ? '1' : '0'));
            self::assertSame(! $serverAutocommit, (bool) $reader->getAttribute(PDO::ATTR_AUTOCOMMIT));
            $snapshot = PdoSnapshotPaginator::forExclusiveRead($reader);
            try {
                $page = $snapshot->paginate($this->descriptor($table), new PageRequest(), $this->config(), static fn(array $row): array => $row);
                self::assertTrue($serverAutocommit);
                self::assertSame(3, $page->filtered);
                self::assertSame(SnapshotConnectionDispositionEnum::REUSABLE, $snapshot->connectionOutcome()?->disposition);
            } catch (SnapshotPaginationException $failure) {
                self::assertFalse($serverAutocommit, $failure->getMessage());
                self::assertSame(SnapshotConnectionDispositionEnum::UNCHANGED, $snapshot->connectionOutcome()?->disposition);
            } finally {
                self::assertFalse($reader->inTransaction());
                self::assertSame(! $serverAutocommit, (bool) $reader->getAttribute(PDO::ATTR_AUTOCOMMIT));
                $reader->exec('SET SESSION autocommit = 1');
            }
        }
    }

    public function testEmptyResultSkipsDataQuery(): void
    {
        [$reader, $table] = $this->connectWithPaginationTable();
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $snapshot = PdoSnapshotPaginator::forExclusiveRead($reader);
        $counts = $this->descriptor($table);
        $query = new PdoPaginationQueryDescriptor(
            $counts->totalSql,
            $counts->totalParams,
            $counts->filteredCountSql,
            $counts->filteredCountParams,
            'SELECT id FROM deliberately_missing_c1_table',
            [],
        );
        $page = $snapshot->paginate($query, new PageRequest(99), $this->config(), static fn(): never => self::fail('Empty pages skip mapping.'));
        self::assertSame(1, $page->page);
        self::assertSame(0, $page->filtered);
        self::assertSame([], $page->data);
        self::assertSame(SnapshotConnectionDispositionEnum::REUSABLE, $snapshot->connectionOutcome()?->disposition);
    }
}
