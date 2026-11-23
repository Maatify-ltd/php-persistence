<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum as Direction;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\PhpPersistence\Tests\Support\Pagination\PaginationFixture;
use PDO;
use PDOException;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class DynamicSortPaginationIntegrationTest extends PaginationIntegrationTestCase
{
    public function testJoinedGridSortsUnicodeAndNullsWithOneUniqueTailAndAnOptionalIndex(): void
    {
        $pdo = $this->connect();
        $parentTable = $this->createPaginationTable($pdo);
        $gridTable = $this->createPaginationTable($pdo);
        $parent = new PaginationFixture($pdo, $parentTable);
        $grid = new PaginationFixture($pdo, $gridTable);
        $time = '2026-01-01 00:00:00.000000';
        $left = $parent->insertItem(1, 'parent', 'Left', 0, true, null, $time);
        $right = $parent->insertItem(1, 'parent', 'Right', 0, true, null, $time);
        $outside = $parent->insertItem(2, 'parent', 'Other tenant', 0, true, null, $time);
        $prefix = str_repeat("\u{0645}", 120);
        $alpha = $grid->insertItem(1, 'grid', $prefix . 'a', $left, true, 'b', $time);
        $alphaTie = $grid->insertItem(1, 'grid', $prefix . 'a', $left, true, 'b', $time);
        $bravo = $grid->insertItem(1, 'grid', $prefix . 'b', $right, true, 'b', $time);
        $charlie = $grid->insertItem(1, 'grid', $prefix . 'C', $right, true, 'a', $time);
        $nullLabel = $grid->insertItem(1, 'grid', $prefix . 'a', $right, true, null, $time);
        $grid->insertItem(1, 'grid', 'Inactive', $right, false, null, $time);
        $grid->insertItem(1, 'grid', 'Deleted', $right, true, null, $time, $time);
        $grid->insertItem(2, 'grid', 'Another tenant', $outside, true, 'b', $time);

        $from = "FROM `$gridTable` g JOIN `$parentTable` p ON g.score = p.id WHERE p.tenant_id = :tenant AND g.deleted_at IS NULL";
        $query = new PdoPaginationQueryDescriptor(
            "SELECT COUNT(*) $from",
            ['tenant' => 1],
            "SELECT COUNT(*) $from AND g.is_active = :active",
            ['tenant' => 1, 'active' => true],
            "SELECT g.id, p.id AS parent_id $from AND g.is_active = :active",
            ['tenant' => 1, 'active' => true],
        );
        $config = new PaginationConfig(new SortWhitelist([
            'label' => 'g.nullable_code', 'name' => 'g.name', 'parent' => 'p.id', 'internal_row' => 'g.id', 'row_alias' => 'g.ID',
        ]), 'name', Direction::ASC, 'internal_row', Direction::DESC, 2);
        $sorts = [['key' => 'label', 'direction' => 'desc'], ['key' => 'name', 'direction' => 'asc'], ['key' => 'parent', 'direction' => 'desc']];
        $paginator = new PdoPaginator();
        $reader = $this->connect();
        foreach ([false, true] as $indexAvailable) {
            if ($indexAvailable) {
                $pdo->exec("CREATE INDEX `idx_grid_mixed` ON `$gridTable` (`nullable_code` DESC, `name` ASC, `id` DESC)");
            }
            $reader->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $reader->beginTransaction();
            try {
                $ids = [];
                for ($page = 1; $page <= 3; ++$page) {
                    $result = $paginator->paginateWithDynamicSort($reader, $query, new PageRequest(page: $page), $config, $sorts, self::idRow(...));
                    self::assertSame([6, 5, 3, $page], [$result->page->total, $result->page->filtered, $result->page->totalPages, $result->page->page]);
                    self::assertSame([
                        ['key' => 'label', 'direction' => 'DESC'],
                        ['key' => 'name', 'direction' => 'ASC'],
                        ['key' => 'parent', 'direction' => 'DESC'],
                        ['key' => null, 'direction' => 'DESC'],
                    ], $result->toArray()['pagination']['effective_sorts']);
                    array_push($ids, ...array_column($result->page->data, 'id'));
                }
                self::assertSame([$alphaTie, $alpha, $bravo, $charlie, $nullLabel], $ids);
                $explicitAlias = $paginator->paginateWithDynamicSort(
                    $reader,
                    $query,
                    new PageRequest(page: PHP_INT_MAX),
                    $config,
                    [['key' => 'label', 'direction' => 'DESC'], ['key' => 'row_alias', 'direction' => 'ASC']],
                    self::idRow(...),
                );
                self::assertSame(1, $explicitAlias->page->page);
                self::assertSame([$alpha, $alphaTie], array_column($explicitAlias->page->data, 'id'));
                self::assertSame($explicitAlias->selectedSorts, $explicitAlias->effectiveSorts);
                self::assertTrue($reader->inTransaction());
            } finally {
                $reader->rollBack();
            }
        }

        $tieDefault = new PaginationConfig($config->sortWhitelist, 'row_alias', Direction::DESC, 'internal_row', Direction::ASC, 2);
        foreach ([[], [['key' => 'row_alias', 'direction' => 'desc']]] as $requestSorts) {
            $result = $paginator->paginateWithDynamicSort($reader, $query, new PageRequest(), $tieDefault, $requestSorts, self::idRow(...));
            self::assertSame([$nullLabel, $charlie], array_column($result->page->data, 'id'));
            self::assertSame([['key' => 'row_alias', 'direction' => 'DESC']], $result->toArray()['pagination']['effective_sorts']);
        }

        $emptyQuery = new PdoPaginationQueryDescriptor("SELECT COUNT(*) $from", ['tenant' => 1], 'SELECT 0 AS filtered_count', [], 'SELECT unavailable_column', []);
        $empty = $paginator->paginateWithDynamicSort($reader, $emptyQuery, new PageRequest(9), $config, $sorts, self::idRow(...));
        self::assertSame([1, 6, 0, [], false, false], [$empty->page->page, $empty->page->total, $empty->page->filtered, $empty->page->data, $empty->page->hasNext, $empty->page->hasPrevious]);
        self::assertSame('label', $empty->page->sortBy);
    }

    public function testOffsetDriftAcrossSnapshotsAndStableReadViewWithinHostTransaction(): void
    {
        [$writer, $table] = $this->connectWithPaginationTable();
        [$first, $second, $third] = $this->insertMultiTenantDataset($writer, $table);
        $reader = $this->connect();
        $reader->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $reader->beginTransaction();
        $paginator = new PdoPaginator();
        $query = $this->descriptor($table);
        $sorts = [['key' => 'score', 'direction' => 'ASC'], ['key' => 'created_at', 'direction' => 'ASC']];
        try {
            $before = $paginator->paginateWithDynamicSort($reader, $query, new PageRequest(1, 2), $this->config(), $sorts, self::idRow(...));
            self::assertSame([$first, $second], array_column($before->page->data, 'id'));

            (new PaginationFixture($writer, $table))->insertItem(1, 'new', 'Concurrent', 0, true, null, '2026-01-01 00:00:00.000000');
            $sameView = $paginator->paginateWithDynamicSort($reader, $query, new PageRequest(2, 2), $this->config(), $sorts, self::idRow(...));
            self::assertSame([3, [$third]], [$sameView->page->filtered, array_column($sameView->page->data, 'id')]);
            self::assertTrue($reader->inTransaction());
        } finally {
            $reader->rollBack();
        }

        $newView = $paginator->paginateWithDynamicSort($reader, $query, new PageRequest(2, 2), $this->config(), $sorts, self::idRow(...));
        self::assertSame([4, [$second, $third]], [$newView->page->filtered, array_column($newView->page->data, 'id')]);
    }

    /** @return iterable<string, array{bool}> */
    public static function bufferingModes(): iterable
    {
        yield 'buffered' => [true];
        yield 'unbuffered' => [false];
    }

    #[DataProvider('bufferingModes')]
    public function testEarlyMapperFailureReleasesCursorAndPreservesHostTransaction(bool $buffered): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        $failure = new RuntimeException('Mapper failed before consuming all rows.');
        $pdo->beginTransaction();
        try {
            try {
                (new PdoPaginator())->paginateWithDynamicSort($pdo, $this->descriptor($table), new PageRequest(), $this->config(), [], static fn(array $row): never => throw $failure);
                self::fail('Expected the mapper failure.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertTrue($pdo->inTransaction());
            self::assertSame('6', $this->scalar($pdo, "SELECT COUNT(*) FROM `$table`"));
        } finally {
            $pdo->rollBack();
        }
    }

    /** @return iterable<string, array{int, class-string<\Throwable>, int}> */
    public static function errorModes(): iterable
    {
        yield 'exception' => [PDO::ERRMODE_EXCEPTION, PDOException::class, 0];
        yield 'silent' => [PDO::ERRMODE_SILENT, PaginationExecutionException::class, 0];
        yield 'warning' => [PDO::ERRMODE_WARNING, PaginationExecutionException::class, 1];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('errorModes')]
    public function testPdoErrorModeAndConnectionRemainHostOwned(int $mode, string $exception, int $expectedWarnings): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);
        $query = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT missing_column FROM `$table`", []);
        $warnings = 0;
        $pdo->beginTransaction();
        set_error_handler(static function () use (&$warnings): bool {
            ++$warnings;

            return true;
        }, E_WARNING);
        try {
            try {
                (new PdoPaginator())->paginateWithDynamicSort($pdo, $query, new PageRequest(), $this->config(), [], self::idRow(...));
                self::fail('Expected the failing data statement to prevent any result.');
            } catch (PDOException|PaginationExecutionException $caught) {
                self::assertInstanceOf($exception, $caught);
            }
            self::assertSame($expectedWarnings, $warnings);
            self::assertSame($mode, $pdo->getAttribute(PDO::ATTR_ERRMODE));
            self::assertTrue($pdo->inTransaction());
            self::assertSame('1', $this->scalar($pdo, 'SELECT 1'));
        } finally {
            restore_error_handler();
            $pdo->rollBack();
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int}
     */
    private static function idRow(array $row): array
    {
        self::assertIsInt($row['id']);

        return ['id' => $row['id']];
    }
}
