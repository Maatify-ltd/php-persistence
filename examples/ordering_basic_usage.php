<?php

declare(strict_types=1);

/**
 * Ordering capability: ScopedOrderingManager.
 *
 * Demonstrates scoped next-position allocation and a locked, clamped move,
 * including the "row not found" boundary and the ownership boundary when the
 * move participates in a caller-owned transaction.
 *
 * Run standalone: php examples/ordering_basic_usage.php
 * Requires a real MySQL/MariaDB connection; see examples/_bootstrap.php.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;

/**
 * Reads {id => display_order} for the scope, cast to int: the pdo_mysql driver
 * may return numeric columns as strings depending on its configuration, and
 * this example's assertions compare against literal int values.
 *
 * @return array<int, int>
 */
function php_persistence_example_read_orders(PDO $pdo, string $quotedTable): array
{
    $statement = php_persistence_example_require_statement(
        $pdo->query("SELECT id, display_order FROM {$quotedTable} WHERE method_id = 7 ORDER BY id ASC"),
        'failed to read back display_order values',
    );

    $orders = [];
    while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
        if (count($row) !== 2) {
            throw new RuntimeException('Unexpected row shape while reading display_order values');
        }
        $orders[php_persistence_example_to_int($row[0])] = php_persistence_example_to_int($row[1]);
    }

    return $orders;
}

$pdo = php_persistence_example_connect();
$table = php_persistence_example_table_name('shipping_rates');
$quotedTable = '`' . $table . '`';

$pdo->exec(
    "CREATE TABLE {$quotedTable} ("
    . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
    . 'method_id INT UNSIGNED NOT NULL, '
    . 'display_order INT UNSIGNED NOT NULL) ENGINE=InnoDB',
);

try {
    $insert = php_persistence_example_require_statement(
        $pdo->prepare("INSERT INTO {$quotedTable} (id, method_id, display_order) VALUES (:id, :method_id, :display_order)"),
        'failed to prepare the seed insert',
    );
    foreach ([[1, 1], [2, 2], [3, 3]] as [$id, $order]) {
        $insert->execute(['id' => $id, 'method_id' => 7, 'display_order' => $order]);
    }

    $config = new ScopedOrderingConfig(table: $table, scopeColumn: 'method_id', deletedAtColumn: null);
    $manager = new ScopedOrderingManager();

    // Input: append a shipping rate to method_id 7 (three existing rows).
    echo "Input -> append a shipping rate to method_id = 7 (three existing rows)\n";
    $nextPosition = (new PdoTransactionRunner($pdo))->run(
        static function () use ($pdo, $config, $manager, $insert): int {
            $position = $manager->getNextPositionForUpdate($pdo, $config, scopeValue: 7);
            // Keep the dependent insert in the same transaction as the locking read.
            $insert->execute(['id' => 4, 'method_id' => 7, 'display_order' => $position]);

            return $position;
        },
    );
    php_persistence_example_assert($nextPosition === 4, 'unexpected next position for three existing rows');
    echo "Result   -> appended row 4 at position {$nextPosition}\n";

    // Public Call: move row id=3 from position three to the front of its scope.
    echo "Input -> moveWithinScope(scopeValue: 7, id: 3, newOrder: 1)\n";
    $moved = $manager->moveWithinScope($pdo, $config, scopeValue: 7, id: 3, newOrder: 1);
    php_persistence_example_assert($moved === true, 'expected the move to succeed');

    $orders = php_persistence_example_read_orders($pdo, $quotedTable);
    php_persistence_example_assert($orders === [1 => 2, 2 => 3, 3 => 1, 4 => 4], 'unexpected display_order values after the move');
    echo "Result   -> row 3 is now first; rows 1 and 2 shifted down (id => display_order: " . json_encode($orders) . ")\n";

    // Boundary: moving a row that does not exist in the scope returns false, not an exception.
    $missing = $manager->moveWithinScope($pdo, $config, scopeValue: 7, id: 999, newOrder: 1);
    php_persistence_example_assert($missing === false, 'moving a non-existent row must return false, not throw');
    echo "Boundary -> moveWithinScope() for a non-existent row returned false (no exception)\n";

    // Boundary: moveWithinScope() only ever participates in a caller-owned
    // transaction; it never commits or finally rolls it back. The caller alone
    // decides the outcome of the surrounding transaction.
    $pdo->beginTransaction();
    try {
        $participatingMove = $manager->moveWithinScope($pdo, $config, scopeValue: 7, id: 1, newOrder: 1);
        php_persistence_example_assert($participatingMove === true, 'expected the participating move to succeed');
        php_persistence_example_assert($pdo->inTransaction(), 'the participating move ended the caller transaction');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    $ordersAfterCallerRollback = php_persistence_example_read_orders($pdo, $quotedTable);
    php_persistence_example_assert(
        $ordersAfterCallerRollback === [1 => 2, 2 => 3, 3 => 1, 4 => 4],
        'moveWithinScope() must not survive the caller rolling back its own transaction',
    );
    echo "Boundary -> the caller's own rollBack() undid the participating move; moveWithinScope() never commits for the caller\n";
} finally {
    if (!$pdo->inTransaction()) {
        $pdo->exec("DROP TABLE IF EXISTS {$quotedTable}");
    }
}

echo "OK\n";
