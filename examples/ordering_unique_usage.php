<?php

declare(strict_types=1);

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

/** Reads the fingerprint of this disposable fixture at deployment time. */
function b1_example_fingerprint(PDO $pdo, string $table): string
{
    $result = php_persistence_example_require_statement($pdo->query('SHOW CREATE TABLE ' . $table), 'Missing table definition');
    $definition = $result->fetchColumn(1);
    $result->closeCursor();
    if (!is_string($definition)) {
        throw new RuntimeException('Missing DDL in SHOW CREATE TABLE.');
    }
    $structural = preg_replace('/^(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+(?= |$)/m', '$1', $definition);
    if ($structural === null) {
        throw new RuntimeException('Unable to fingerprint the table.');
    }

    return hash('sha256', $structural);
}

$setup = php_persistence_example_connect();
$schema = php_persistence_example_env('PHP_PERSISTENCE_IT_DATABASE');
$items = $schema . '.' . php_persistence_example_table_name('b1_items');
$anchor = $schema . '.' . php_persistence_example_table_name('b1_anchor');
$ordering = new ScopedOrderingConfig(table: $items, orderColumn: 'pos', deletedAtColumn: null);
$itemsSql = $ordering->quotedTable();
$anchorSql = (new ScopedOrderingConfig(table: $anchor, deletedAtColumn: null))->quotedTable();
try {
    $setup->exec("CREATE TABLE $anchorSql (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
    $setup->exec("INSERT INTO $anchorSql (id) VALUES (1)");
    $setup->exec("CREATE TABLE $itemsSql (id INT NOT NULL PRIMARY KEY, pos INT NOT NULL, UNIQUE (pos)) ENGINE=InnoDB");
    $version = php_persistence_example_require_statement($setup->query('SELECT VERSION()'), 'Missing server version')->fetchColumn();
    // A real Host issues the reviewed receipt outside the request path and
    // obtains the current revision from its deployment settings.
    $config = new UniqueOrderingConfig(
        ordering: $ordering,
        anchorTable: $anchor,
        anchorIdColumn: 'id',
        globalAnchorId: 1,
        maxPosition: 11,
        maxScopeRows: 10,
        schemaAttestation: [
            'formatVersion' => 1,
            'schemaRevision' => 'ordering-demo',
            'serverVersion' => $version,
            'itemsTable' => $items,
            'anchorTable' => $anchor,
            'itemsFingerprint' => b1_example_fingerprint($setup, $itemsSql),
            'anchorFingerprint' => b1_example_fingerprint($setup, $anchorSql),
            'dependenciesReviewed' => true,
            'writersReviewed' => true,
            'anchorProvisioned' => true,
        ],
        activeSchemaRevision: 'ordering-demo',
    );
    $catalog = new ProtectedTransactionCommandCatalog([
        'insert' => new ProtectedTransactionCommandPlan("INSERT INTO $itemsSql (id, pos) VALUES (:id, :position)", ['id', 'position']),
        'delete' => new ProtectedTransactionCommandPlan(
            "DELETE FROM $itemsSql WHERE " . $ordering->quotedIdColumn() . ' = :id AND ' . $ordering->quotedOrderColumn() . ' = :position',
            ['id', 'position'],
        ),
    ]);
    $checkout = PdoProtectedTransactionCheckout::attachExclusive(php_persistence_example_connect(), $catalog);
    $manager = new ProtectedScopedOrderingManager();
    try {
        $checkout->begin();
        foreach ([1, 2, 3] as $id) {
            $position = $manager->getNextUniquePositionForAppend($checkout, $config, scopeId: null);
            php_persistence_example_assert($position === $id, 'Unexpected dense append position.');
            $count = $checkout->runRegisteredCommand('insert', ['id' => $id, 'position' => $position]);
            php_persistence_example_assert($count === 1, 'Expected a single row insert.');
        }
        php_persistence_example_assert($manager->moveUniqueWithinScope($checkout, $config, null, 3, 1), 'Expected row 3.');
        php_persistence_example_assert($manager->deleteUniqueWithinScope($checkout, $config, null, 1, 'delete'), 'Expected row 1.');
        $checkout->commit();
    } catch (Throwable $primary) {
        if ($checkout->settlement() === null) {
            try {
                $checkout->rollBack();
            } catch (Throwable) {
                // Preserve the primary error. Settlement governs disposal.
            }
        }
        throw $primary;
    } finally {
        if ($checkout->settlement()?->disposition === ProtectedTransactionDispositionEnum::DiscardRequired) {
            $checkout->discardForHost();
        } else {
            $checkout->returnForHost();
        }
    }
    $result = php_persistence_example_require_statement($setup->query("SELECT id, pos FROM $itemsSql ORDER BY pos"), 'Missing ordered rows');
    $positions = [];
    while (($row = $result->fetch(PDO::FETCH_NUM)) !== false) {
        $positions[php_persistence_example_to_int($row[0])] = php_persistence_example_to_int($row[1]);
    }
    $result->closeCursor();
    php_persistence_example_assert($positions === [3 => 1, 2 => 2], 'Unexpected order after compaction.');
    echo "Protected ordering appended, moved and compacted a UNIQUE list.\n";
} finally {
    $setup->exec("DROP TABLE IF EXISTS $itemsSql");
    $setup->exec("DROP TABLE IF EXISTS $anchorSql");
}
