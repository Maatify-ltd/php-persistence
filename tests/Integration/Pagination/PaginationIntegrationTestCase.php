<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Closure;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Support\Pagination\InterleavingPdo;
use Maatify\PhpPersistence\Tests\Support\Pagination\PaginationFixture;
use PDO;

/**
 * Real-database base for the Pagination capability. Each test creates its own
 * uniquely named fixture table (never a fixed shared name) so repeated and
 * parallel runs leave no residue, per DATABASE_SUPPORT_AND_TEST_POLICY.md §5.
 */
abstract class PaginationIntegrationTestCase extends IntegrationTestCase
{
    /** @var list<string> */
    private array $paginationTables = [];

    /**
     * Creates the Pagination fixture schema (golden-captured from legacy
     * `tests/Fixtures/MySql/create_pagination_items_table.sql` at 1f6a7aa) under a
     * run-unique table name, and returns that name.
     *
     * @return non-empty-string
     */
    protected function createPaginationTable(PDO $pdo): string
    {
        $table = 'it_pagination_' . bin2hex(random_bytes(6));
        $pdo->exec(
            'CREATE TABLE `' . $table . '` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`tenant_id` INT UNSIGNED NOT NULL, '
                . '`category` VARCHAR(32) NOT NULL, '
                . '`name` VARCHAR(128) NOT NULL, '
                . '`score` INT NOT NULL, '
                . '`is_active` TINYINT(1) NOT NULL, '
                . '`nullable_code` VARCHAR(32) NULL, '
                . '`created_at` DATETIME(6) NOT NULL, '
                . '`deleted_at` DATETIME(6) NULL, '
                . 'PRIMARY KEY (`id`), '
                . 'KEY `idx_pagination_base` (`tenant_id`, `deleted_at`, `id`), '
                . 'KEY `idx_pagination_filter` (`tenant_id`, `category`, `is_active`, `nullable_code`, `score`, `id`), '
                . 'KEY `idx_pagination_sort` (`created_at`, `id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->paginationTables[] = $table;

        return $table;
    }

    /**
     * Opens a fresh connection and its own uniquely named Pagination fixture table.
     *
     * @return array{PDO, non-empty-string}
     */
    protected function connectWithPaginationTable(): array
    {
        $pdo = $this->connect();
        $table = $this->createPaginationTable($pdo);

        return [$pdo, $table];
    }

    /**
     * A real connection, with the same settings as {@see self::connect()}, that invokes
     * $beforePrepare(callNumber, sql) immediately before each `prepare()`. Not registered
     * for the leaked-transaction check: the caller is responsible for ending any
     * transaction it starts on the returned connection.
     *
     * @param Closure(int, string): void $beforePrepare
     */
    protected function connectInterleaving(Closure $beforePrepare): PDO
    {
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');

        return new InterleavingPdo($dsn, self::setting('USER'), self::setting('PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ], $beforePrepare);
    }

    protected function config(): PaginationConfig
    {
        return new PaginationConfig(
            new SortWhitelist([
                'id' => 'id',
                'score' => 'score',
                'created_at' => 'created_at',
                'same_id' => 'id',
            ]),
            'created_at',
            SortDirectionEnum::Desc,
            'id',
            SortDirectionEnum::Asc,
            2,
            1,
            50,
        );
    }

    /**
     * @param array<string, int|string|bool|null> $total
     * @param array<string, int|string|bool|null> $filtered
     * @param array<string, int|string|bool|null> $data
     */
    protected function descriptor(string $table, array $total = [], array $filtered = [], array $data = []): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor(
            "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = :tenant_total AND deleted_at IS NULL",
            $total ?: ['tenant_total' => 1],
            "SELECT COUNT(*) AS filtered_count FROM `$table` WHERE tenant_id = :tenant_filtered AND deleted_at IS NULL AND is_active = :active_filtered",
            $filtered ?: ['tenant_filtered' => 1, 'active_filtered' => true],
            "SELECT id, tenant_id, category, name, score, is_active, nullable_code, created_at, deleted_at FROM `$table` WHERE tenant_id = :tenant_data AND deleted_at IS NULL AND is_active = :active_data",
            $data ?: ['tenant_data' => 1, 'active_data' => true],
        );
    }

    /** @return list<int> */
    protected function insertMultiTenantDataset(PDO $pdo, string $table): array
    {
        $fixture = new PaginationFixture($pdo, $table);

        return [
            $fixture->insertItem(1, 'book', 'Alpha', 10, true, null, '2026-01-01 00:00:00.000000'),
            $fixture->insertItem(1, 'book', 'Beta', 10, true, 'B', '2026-01-02 00:00:00.000000'),
            $fixture->insertItem(1, 'tool', 'Gamma', 20, true, 'C', '2026-01-03 00:00:00.000000'),
            $fixture->insertItem(1, 'book', 'Inactive', 30, false, null, '2026-01-04 00:00:00.000000'),
            $fixture->insertItem(1, 'book', 'Deleted', 40, true, null, '2026-01-05 00:00:00.000000', '2026-01-06 00:00:00.000000'),
            $fixture->insertItem(2, 'book', 'Other Tenant', 50, true, null, '2026-01-07 00:00:00.000000'),
        ];
    }

    protected function tearDown(): void
    {
        if ($this->paginationTables !== []) {
            $cleaner = $this->connect();
            foreach ($this->paginationTables as $table) {
                $cleaner->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
            $this->paginationTables = [];
        }

        parent::tearDown();
    }
}
