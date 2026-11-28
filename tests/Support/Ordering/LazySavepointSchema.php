<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Ordering;

use PDO;

/**
 * Test-only schema for the moveWithinScope() operation-local savepoint tests (B-04), golden
 * captured from legacy tests/Support/MySql/LazySavepointSchema.php (1f6a7aa, provenance gate
 * recorded in the WU-8 pull request), with run-unique table names instead of legacy's fixed
 * shared names, and with the AUDIT/FILL tables dropped: legacy used them only for a BEFORE/AFTER
 * UPDATE trigger that this project's database-scoped Integration user cannot create (confirmed
 * against mysql:8.4.13: error 1419/1458, binlog ON, no SUPER/`log_bin_trust_function_creators`).
 * The deadlock scenario this schema now supports (ScopedOrderingManagerLazySavepointTest) uses
 * the generic tests/Integration/Support/{PartnerProcess,DeadlockPartner}.php mechanism directly
 * against PLAIN's own rows instead.
 *
 * Tables (all InnoDB, utf8mb4, PRIMARY KEY (id), no foreign keys or joins to host tables):
 * - PLAIN:  global (NULL-scope) ordering table. Display order is `display_order`, mutated by
 *           the shift. `deleted_at` is the soft-delete column: every row stays NULL (active).
 *           `updated_at` is the optional target mutation timestamp. `label` is free text.
 *           Uniqueness: none besides the primary key.
 * - UNIQUE: same columns as PLAIN plus UNIQUE KEY (`updated_at`), so that the target write can
 *           fail with 1062 after the range shift.
 * - MARKER: records the caller's earlier work inside its transaction.
 *
 * Indexes are deliberate: there is NO index on `display_order`. The scope lock is
 * `SELECT ... ORDER BY display_order, id FOR UPDATE` and the tests assert that it keeps every
 * row and the insert gap (id 6) locked; an extra index would change the access path and
 * therefore which records and gaps are locked, so the table stays PRIMARY KEY plus the
 * documented UNIQUE.
 */
final readonly class LazySavepointSchema
{
    public const MOVE_ID = 5;
    public const MOVE_TO = 2;
    public const STAMP_OK = '2026-02-01 00:00:00';
    /** Equals the seeded updated_at of row 1: a duplicate under UNIQUE (updated_at). */
    public const STAMP_DUP = '2026-01-01 00:00:00';

    /** @var non-empty-string */
    public string $plain;

    /** @var non-empty-string */
    public string $unique;

    /** @var non-empty-string */
    public string $marker;

    public function __construct(private PDO $pdo)
    {
        $suffix = bin2hex(random_bytes(6));
        $this->plain = 'it_ordering_lsp_plain_' . $suffix;
        $this->unique = 'it_ordering_lsp_unique_' . $suffix;
        $this->marker = 'it_ordering_lsp_marker_' . $suffix;
    }

    public function initialize(): void
    {
        foreach ([$this->plain => '', $this->unique => ', UNIQUE KEY `u_updated` (`updated_at`)'] as $table => $extra) {
            $this->pdo->exec(
                'CREATE TABLE `' . $table . '` ('
                    . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                // DEFAULT 0 only matters for the filler rows the DeadlockPartner INSERT adds
                // (see `payload` above); every row this test suite seeds sets it explicitly.
                    . ' `display_order` INT NOT NULL DEFAULT 0,'
                    . ' `deleted_at` DATETIME NULL DEFAULT NULL,'
                    . ' `updated_at` DATETIME NULL DEFAULT NULL,'
                // `payload` carries no ordering meaning; it exists only so the generic
                // tests/Integration/Support/DeadlockPartner.php filler INSERT (used by
                // ScopedOrderingManagerLazySavepointTest's genuine-deadlock test) can make
                // itself heavy against this exact table without a dedicated partner script.
                    . ' `payload` VARCHAR(32) NULL DEFAULT NULL,'
                    . ' `label` VARCHAR(191) NOT NULL DEFAULT \'\','
                    . ' PRIMARY KEY (`id`)' . $extra
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            );

            for ($i = 1; $i <= 5; $i++) {
                $this->pdo->exec(
                    'INSERT INTO `' . $table . '` (`display_order`, `updated_at`, `label`)'
                        . " VALUES ({$i}, '2026-01-0{$i} 00:00:00', 'row {$i}')",
                );
            }
        }

        $this->pdo->exec('CREATE TABLE `' . $this->marker . '` ('
            . ' `id` INT NOT NULL AUTO_INCREMENT,'
            . ' `note` VARCHAR(64) NOT NULL,'
            . ' PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB');
    }

    public function drop(): void
    {
        foreach ([$this->plain, $this->unique, $this->marker] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
