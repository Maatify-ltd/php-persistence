<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\FaultInjectingPdo;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingFixture;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingStateReader;
use PDO;

/**
 * Real-database base for the Ordering capability (B-01 to B-07). Each test creates its own
 * uniquely named global and/or scoped fixture table (never a fixed shared name) so repeated
 * and parallel runs leave no residue, per DATABASE_SUPPORT_AND_TEST_POLICY.md §5. Schemas are
 * golden-captured from legacy tests/Fixtures/MySql/{create_global_ordering_table,create_scoped_ordering_table}.sql
 * (1f6a7aa, provenance gate recorded in the WU-8 pull request).
 */
abstract class OrderingIntegrationTestCase extends IntegrationTestCase
{
    /** @var list<string> */
    private array $orderingTables = [];

    protected PDO $pdo;

    /** @var non-empty-string */
    protected string $globalTable;

    /** @var non-empty-string */
    protected string $scopedTable;

    protected OrderingFixture $fixture;

    protected function setUp(): void
    {
        $this->pdo = $this->connect();
        $this->globalTable = $this->createGlobalOrderingTable($this->pdo);
        $this->scopedTable = $this->createScopedOrderingTable($this->pdo);
        $this->fixture = new OrderingFixture($this->pdo, $this->globalTable, $this->scopedTable);
    }

    protected function pdo(): PDO
    {
        return $this->pdo;
    }

    protected function reader(?PDO $pdo = null): OrderingStateReader
    {
        return new OrderingStateReader($pdo ?? $this->pdo, $this->globalTable, $this->scopedTable);
    }

    /** @return non-empty-string */
    protected function createGlobalOrderingTable(PDO $pdo): string
    {
        $table = 'it_ordering_global_' . bin2hex(random_bytes(6));
        $pdo->exec(
            'CREATE TABLE `' . $table . '` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`display_order` INT NOT NULL, '
                . '`deleted_at` DATETIME NULL DEFAULT NULL, '
                . '`label` VARCHAR(191) NOT NULL, '
                . 'PRIMARY KEY (`id`), '
                . 'KEY `idx_deleted_at` (`deleted_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->orderingTables[] = $table;

        return $table;
    }

    /** @return non-empty-string */
    protected function createScopedOrderingTable(PDO $pdo): string
    {
        $table = 'it_ordering_scoped_' . bin2hex(random_bytes(6));
        $pdo->exec(
            'CREATE TABLE `' . $table . '` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`scope_key` VARCHAR(191) NULL DEFAULT NULL, '
                . '`display_order` INT NOT NULL, '
                . '`deleted_at` DATETIME NULL DEFAULT NULL, '
                . '`updated_at` DATETIME NULL DEFAULT NULL, '
                . '`label` VARCHAR(191) NOT NULL, '
                . 'PRIMARY KEY (`id`), '
                . 'KEY `idx_scope_order` (`scope_key`, `display_order`), '
                . 'KEY `idx_deleted_at` (`deleted_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->orderingTables[] = $table;

        return $table;
    }

    /**
     * A real connection, with the same settings as {@see self::connect()}, that injects
     * control-statement and prepare() failures. Not registered for the leaked-transaction
     * check: the caller is responsible for ending any transaction it starts.
     */
    protected function connectFaultInjecting(): FaultInjectingPdo
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ];
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');

        return new FaultInjectingPdo($dsn, self::setting('USER'), self::setting('PASSWORD'), $options);
    }

    protected function globalConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: $this->globalTable);
    }

    protected function globalConfigWithoutSoftDeletes(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: $this->globalTable, deletedAtColumn: null);
    }

    protected function scopedConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: $this->scopedTable, scopeColumn: 'scope_key');
    }

    protected function scopedConfigWithoutSoftDeletes(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: $this->scopedTable, scopeColumn: 'scope_key', deletedAtColumn: null);
    }

    protected function nullableScopedConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(
            table: $this->scopedTable,
            scopeColumn: 'scope_key',
            nullableScope: true,
        );
    }

    /**
     * Legacy documents the "a locking read reflects the latest committed state" guarantee
     * (B-02/B-03) as holding on MySQL 8.0/8.4 and on MariaDB with `innodb_snapshot_isolation=OFF`,
     * and explicitly not on MariaDB with the setting ON (the 11.8+ default), where the same
     * locking read instead fails with a documented PDOException, driver code 1020
     * (DATABASE_SUPPORT_AND_TEST_POLICY.md §7). Tests that port legacy's exact "sees the latest
     * committed state" assertions arrange that documented precondition explicitly, test-only, on
     * every connection that takes a stale-snapshot locking read, so the same ported assertions
     * hold on every matrix engine; this is not a package-side setting mutation (the production
     * code never touches this setting). The ON hazard itself is proven separately by
     * {@see ScopedOrderingManagerMariaDbSnapshotIsolationHazardTest}.
     */
    protected function arrangeLatestCommittedStateGuarantee(PDO $pdo): void
    {
        if (self::engine() === 'mariadb') {
            $pdo->exec('SET SESSION innodb_snapshot_isolation = OFF');
        }
    }

    protected function tearDown(): void
    {
        if ($this->orderingTables !== []) {
            $cleaner = $this->connect();
            foreach ($this->orderingTables as $table) {
                $cleaner->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
            $this->orderingTables = [];
        }

        parent::tearDown();
    }
}
