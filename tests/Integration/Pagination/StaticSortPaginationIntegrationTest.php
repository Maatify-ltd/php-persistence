<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Config\StaticSortPlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum as Direction;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\PhpPersistence\Tests\Support\Pagination\PaginationFixture;
use PDO;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class StaticSortPaginationIntegrationTest extends PaginationIntegrationTestCase
{
    public function testProductLikeWindowAndDirectOrderingAgreeAcrossPagesAndFilters(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $fixture = new PaginationFixture($pdo, $table);
        $time = '2026-01-01 00:00:00.000000';
        $b = $fixture->insertItem(1, 'b', 'Scope b', 1, true, null, $time);
        $first = $fixture->insertItem(1, 'a', 'First tie', 2, true, null, $time);
        $second = $fixture->insertItem(1, 'a', 'Second tie', 2, true, 'variant', $time);
        $fixture->insertItem(1, 'a', 'Deleted', 1, true, null, $time, $time);
        $a = $fixture->insertItem(1, 'a', 'First in scope', 1, true, 'variant', $time);
        $fixture->insertItem(2, 'a', 'Other product', 1, true, null, $time);

        $columns = 'id, category, score, nullable_code, created_at, deleted_at, created_at AS updated_at';
        $window = 'ROW_NUMBER() OVER (ORDER BY category ASC, score ASC, id ASC) AS business_order';
        $sharedSorts = ['id' => 'id', 'created_at' => 'created_at', 'updated_at' => 'updated_at'];
        $legacy = self::paginationConfig(['business_order' => 'business_order', ...$sharedSorts], 'business_order', 'id');
        $plan = new StaticSortPlan(self::paginationConfig(['business_order' => 'category', ...$sharedSorts], 'business_order', 'id'), [
            'business_order' => [['identifier' => 'score'], ['identifier' => 'id']],
            'id' => null,
            'created_at' => null,
            'updated_at' => null,
        ]);
        $filters = [
            'active' => [' AND deleted_at IS NULL', []],
            'all' => ['', []],
            'deleted' => [' AND deleted_at IS NOT NULL', []],
            'product-level' => [' AND deleted_at IS NULL AND nullable_code IS NULL', []],
            'variant' => [' AND deleted_at IS NULL AND nullable_code = :code', ['code' => 'variant']],
            'missing' => [' AND nullable_code = :code', ['code' => 'missing']],
        ];
        $paginator = new PdoPaginator();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        try {
            foreach ($filters as $filterName => [$filter, $params]) {
                $from = " FROM `$table` WHERE tenant_id = :tenant" . $filter;
                $countSql = 'SELECT COUNT(*)' . $from;
                $bindings = ['tenant' => 1] + $params;
                $oldQuery = new PdoPaginationQueryDescriptor($countSql, $bindings, $countSql, $bindings, "SELECT $columns, $window" . $from, $bindings);
                $newQuery = new PdoPaginationQueryDescriptor($countSql, $bindings, $countSql, $bindings, "SELECT $columns" . $from, $bindings);
                $mapper = static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'category', 'score', 'nullable_code', 'created_at', 'deleted_at', 'updated_at']));

                foreach ([['business_order', 'ASC'], ['business_order', 'DESC'], ['id', 'DESC'], ['created_at', 'ASC'], ['updated_at', 'DESC']] as [$key, $direction]) {
                    $ids = [];
                    $pages = 1;
                    for ($index = 1; $index <= $pages + 1; ++$index) {
                        $request = new PageRequest($index, 2, $key, $direction);
                        $expected = $paginator->paginate($pdo, $oldQuery, $request, $legacy, $mapper);
                        $actual = $paginator->paginateWithStaticSort($pdo, $newQuery, $request, $plan, $mapper);
                        self::assertSame($expected->toArray(), $actual->toArray(), "$filterName $key $direction page $index");
                        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($actual, JSON_THROW_ON_ERROR));
                        self::assertSame($key, $actual->sortBy);
                        if ($index === 1) {
                            $pages = max(1, $actual->totalPages);
                        }
                        if ($index <= $pages) {
                            array_push($ids, ...array_column($actual->data, 'id'));
                        } else {
                            self::assertSame(1, $actual->page);
                        }
                    }
                    if ($filterName === 'active' && $key === 'business_order') {
                        $ascending = [$a, $first, $second, $b];
                        self::assertSame($direction === 'ASC' ? $ascending : array_reverse($ascending), $ids);
                    }
                }
            }
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }

    public function testI18nLikeGridPreservesLongUnicodeOrderAndHostHintWhenQualified(): void
    {
        $setup = $this->connect();
        $keys = $this->createPaginationTable($setup);
        $translations = $this->createPaginationTable($setup);
        $keyFixture = new PaginationFixture($setup, $keys);
        $translationFixture = new PaginationFixture($setup, $translations);
        // The I18n hint is qualified on MySQL 8.4. Use a portable long prefix
        // on other cells and keep their statements free of engine-specific hints.
        $hinted = self::engine() === 'mysql' && str_starts_with(self::setting('EXPECTED_VERSION'), '8.4.');
        $prefix = str_repeat($hinted ? "\u{337F}" : "\u{0645}", 127);
        $time = '2026-01-01 00:00:00.000000';
        $delta = $keyFixture->insertItem(1, 'grid', $prefix . ($hinted ? "\u{337F}" : 'z'), 0, true, null, $time);
        $alpha = $keyFixture->insertItem(1, 'grid', $prefix . 'a', 0, true, null, $time);
        $charlie = $keyFixture->insertItem(1, 'grid', $prefix . 'C', 0, true, null, $time);
        $bravo = $keyFixture->insertItem(1, 'grid', $prefix . 'b', 0, true, null, $time);
        $translation = $translationFixture->insertItem(1, 'ar', 'Alpha translated', $alpha, true, null, $time);
        if ($hinted) {
            self::assertSame('2048', $this->scalar($setup, "SELECT OCTET_LENGTH(WEIGHT_STRING(name)) FROM `$keys` WHERE id = $delta"));
        }

        $sql = [];
        $pdo = $this->connectInterleaving(static function (int $call, string $statement) use (&$sql): void {
            $sql[] = $statement;
        });
        $sortLength = $this->scalar($pdo, 'SELECT @@SESSION.max_sort_length');
        $from = "FROM `$keys` k CROSS JOIN (SELECT 'ar' AS language_code UNION ALL SELECT 'en') lc "
            . "LEFT JOIN `$translations` t ON t.score = k.id AND t.category = lc.language_code WHERE k.tenant_id = :tenant";
        $hint = $hinted ? '/*+ SET_VAR(max_sort_length=2048) */ ' : '';
        $count = "SELECT COUNT(*) $from";
        $query = new PdoPaginationQueryDescriptor(
            $count,
            ['tenant' => 1],
            $count,
            ['tenant' => 1],
            "SELECT {$hint}k.id AS key_id, lc.language_code, t.id AS translation_id $from",
            ['tenant' => 1],
        );
        $sorts = ['key_part' => 'k.name', 'language_code' => 'lc.language_code'];
        $plan = new StaticSortPlan(self::paginationConfig($sorts, 'key_part', 'language_code'), [
            'key_part' => [['identifier' => 'lc.language_code', 'direction' => Direction::ASC]],
            'language_code' => [['identifier' => 'k.name', 'direction' => Direction::ASC]],
        ]);
        $paginator = new PdoPaginator();
        foreach ([
            ['key_part', 'ASC', 'key_part', false],
            ['key_part', 'DESC', 'key_part', true],
            ['language_code', 'ASC', 'language_code', false],
            [' language_code ', ' desc ', 'language_code', true],
            ['language_code', 'unknown', 'language_code', false],
            ['unknown', 'DESC', 'key_part', true],
        ] as [$key, $direction, $primary, $descending]) {
            $byLanguage = $primary === 'language_code';
            $legacy = self::paginationConfig($sorts, 'key_part', $byLanguage ? 'key_part' : 'language_code');
            $ascendingIds = [$alpha, $bravo, $charlie, $delta];
            $expectedCells = [];
            if ($byLanguage) {
                foreach ($descending ? ['en', 'ar'] : ['ar', 'en'] as $code) {
                    foreach ($ascendingIds as $id) {
                        $expectedCells[] = [$id, $code, $id === $alpha && $code === 'ar' ? $translation : null];
                    }
                }
            } else {
                foreach ($descending ? array_reverse($ascendingIds) : $ascendingIds as $id) {
                    foreach (['ar', 'en'] as $code) {
                        $expectedCells[] = [$id, $code, $id === $alpha && $code === 'ar' ? $translation : null];
                    }
                }
            }

            $cells = [];
            for ($index = 1; $index <= 4; ++$index) {
                $request = new PageRequest($index, 2, $key, $direction);
                $mapper = static fn(array $row): array => [$row['key_id'], $row['language_code'], $row['translation_id']];
                $expected = $paginator->paginate($pdo, $query, $request, $legacy, $mapper);
                $actual = $paginator->paginateWithStaticSort($pdo, $query, $request, $plan, $mapper);
                self::assertSame($expected->toArray(), $actual->toArray());
                self::assertSame([8, 8, $primary, $descending ? Direction::DESC : Direction::ASC], [$actual->total, $actual->filtered, $actual->sortBy, $actual->sortDirection]);
                self::assertSame(array_slice($sql, -6, 3), array_slice($sql, -3));
                self::assertStringNotContainsString('SET_VAR', $sql[count($sql) - 2]);
                self::assertSame($hinted ? 1 : 0, substr_count($sql[count($sql) - 1], 'SET_VAR(max_sort_length=2048)'));
                array_push($cells, ...$actual->data);
            }
            self::assertSame($expectedCells, $cells);
        }
        self::assertSame($sortLength, $this->scalar($pdo, 'SELECT @@SESSION.max_sort_length'));
    }

    /** @return array<string, array{bool}> */
    public static function bufferingModes(): array
    {
        return ['buffered' => [true], 'unbuffered' => [false]];
    }

    #[DataProvider('bufferingModes')]
    public function testMapperFailureLeavesHostTransactionAndCursorUsable(bool $buffered): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        $plan = new StaticSortPlan($this->config(), ['created_at' => [['identifier' => 'score'], ['identifier' => 'id']]]);
        $failure = new RuntimeException('mapper failed before reading all the rows');

        $pdo->beginTransaction();
        try {
            try {
                (new PdoPaginator())->paginateWithStaticSort($pdo, $this->descriptor($table), new PageRequest(), $plan, static fn(array $row): never => throw $failure);
                self::fail('Expected the mapper throwable.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertTrue($pdo->inTransaction());
            self::assertSame('6', $this->scalar($pdo, "SELECT COUNT(*) FROM `$table`"));
        } finally {
            $pdo->rollBack();
        }
    }

    /** @param non-empty-array<non-empty-string, non-empty-string> $sorts */
    private static function paginationConfig(array $sorts, string $primary, string $tie): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist($sorts), $primary, Direction::ASC, $tie, Direction::ASC, 2);
    }
}
