<?php

declare(strict_types=1);

/**
 * Ordering capability: ScopedOrderingManager.
 *
 * Demonstrates appending to an existing scope, including its first row, and
 * a locked, clamped move. The host uses a shipping-method row to serialize
 * appends even when that method has no shipping rates yet.
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
function php_persistence_example_read_orders(PDO $pdo, string $quotedTable, int $methodId): array
{
    $statement = php_persistence_example_require_statement(
        $pdo->prepare("SELECT id, display_order FROM {$quotedTable} WHERE method_id = :method_id ORDER BY id ASC"),
        'failed to read back display_order values',
    );
    php_persistence_example_assert($statement->execute(['method_id' => $methodId]), 'failed to query display_order values');

    $orders = [];
    while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
        if (count($row) !== 2) {
            throw new RuntimeException('Unexpected row shape while reading display_order values');
        }
        $orders[php_persistence_example_to_int($row[0])] = php_persistence_example_to_int($row[1]);
    }
    php_persistence_example_assert($statement->errorCode() === '00000', 'failed to fetch display_order values');

    return $orders;
}

$pdo = php_persistence_example_connect();
$table = php_persistence_example_table_name('shipping_rates');
$quotedTable = '`' . $table . '`';
$quotedMethods = '`' . php_persistence_example_table_name('shipping_methods') . '`';

php_persistence_example_assert(
    $pdo->exec(
        "CREATE TABLE {$quotedTable} ("
            . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'method_id INT UNSIGNED NOT NULL, '
            . 'display_order INT UNSIGNED NOT NULL, '
            . 'KEY method_order (method_id, display_order)) ENGINE=InnoDB',
    ) !== false,
    'failed to create the ordering fixture table',
);

$methodsCreated = false;
try {
    php_persistence_example_assert(
        $pdo->exec("CREATE TABLE {$quotedMethods} (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB") !== false,
        'failed to create the shipping-method fixture table',
    );
    $methodsCreated = true;
    php_persistence_example_assert(
        $pdo->exec("INSERT INTO {$quotedMethods} (id) VALUES (7), (8)") !== false,
        'failed to seed the shipping-method rows',
    );

    // Use explicit IDs so the disposable fixture has predictable rows to move.
    $insert = php_persistence_example_require_statement(
        $pdo->prepare("INSERT INTO {$quotedTable} (id, method_id, display_order) VALUES (:id, :method_id, :display_order)"),
        'failed to prepare the shipping-rate insert',
    );
    foreach ([[1, 1], [2, 2], [3, 3]] as [$id, $order]) {
        php_persistence_example_assert(
            $insert->execute(['id' => $id, 'method_id' => 7, 'display_order' => $order]),
            'failed to insert an ordering fixture row',
        );
    }

    $config = new ScopedOrderingConfig(table: $table, scopeColumn: 'method_id', deletedAtColumn: null);
    $manager = new ScopedOrderingManager();

    $append = static function (int $methodId, int $rateId) use ($pdo, $quotedMethods, $config, $manager, $insert): int {
        return (new PdoTransactionRunner($pdo))->run(
            static function () use ($pdo, $quotedMethods, $config, $manager, $insert, $methodId, $rateId): int {
                // Every competing writer must lock this existing parent row before reading the order.
                $parent = php_persistence_example_require_statement(
                    $pdo->prepare("SELECT id FROM {$quotedMethods} WHERE id = :id FOR UPDATE"),
                    'failed to prepare the shipping-method lock',
                );
                php_persistence_example_assert($parent->execute(['id' => $methodId]), 'failed to lock the shipping method');
                php_persistence_example_assert($parent->fetchColumn() !== false, 'failed to read an existing shipping method');
                php_persistence_example_assert($parent->closeCursor(), 'failed to close the shipping-method cursor');

                // Closing the cursor does not release the row lock. The transaction still owns it.
                $position = $manager->getNextPositionForUpdate($pdo, $config, scopeValue: $methodId);
                php_persistence_example_assert(
                    $insert->execute(['id' => $rateId, 'method_id' => $methodId, 'display_order' => $position]),
                    'failed to insert the shipping rate',
                );

                return $position;
            },
        );
    };

    // Input: append a shipping rate to method_id 7 (three existing rows).
    echo "Input -> append a shipping rate to method_id = 7 (three existing rows)\n";
    $nextPosition = $append(7, 4);
    php_persistence_example_assert($nextPosition === 4, 'unexpected next position for three existing rows');
    echo "Result   -> appended row 4 at position {$nextPosition}\n";

    // Move row 3 to the front. This fixture has no competing writer. When
    // moves share a scope with concurrent appends, use the same parent lock.
    echo "Input -> moveWithinScope(scopeValue: 7, id: 3, newOrder: 1)\n";
    $moved = $manager->moveWithinScope($pdo, $config, scopeValue: 7, id: 3, newOrder: 1);
    php_persistence_example_assert($moved === true, 'expected the move to succeed');

    $orders = php_persistence_example_read_orders($pdo, $quotedTable, 7);
    php_persistence_example_assert($orders === [1 => 2, 2 => 3, 3 => 1, 4 => 4], 'unexpected display_order values after the move');
    echo "Result   -> row 3 is now first; rows 1 and 2 shifted down (id => display_order: " . json_encode($orders) . ")\n";

    // Boundary: moving a row that does not exist in the scope returns false, not an exception.
    $missing = $manager->moveWithinScope($pdo, $config, scopeValue: 7, id: 999, newOrder: 1);
    php_persistence_example_assert($missing === false, 'moving a non-existent row must return false, not throw');
    echo "Boundary -> moveWithinScope() for a non-existent row returned false (no exception)\n";

    // Boundary: moveWithinScope() starts and owns a transaction on an idle
    // connection. When a caller transaction is active, the move participates
    // in it and the caller alone decides its final outcome.
    php_persistence_example_assert($pdo->beginTransaction(), 'failed to begin the caller-owned transaction');
    try {
        $participatingMove = $manager->moveWithinScope($pdo, $config, scopeValue: 7, id: 1, newOrder: 1);
        php_persistence_example_assert($participatingMove === true, 'expected the participating move to succeed');
        php_persistence_example_assert($pdo->inTransaction(), 'the participating move ended the caller transaction');
    } finally {
        if ($pdo->inTransaction()) {
            php_persistence_example_assert($pdo->rollBack(), 'failed to roll back the caller-owned transaction');
        }
    }

    $ordersAfterCallerRollback = php_persistence_example_read_orders($pdo, $quotedTable, 7);
    php_persistence_example_assert(
        $ordersAfterCallerRollback === [1 => 2, 2 => 3, 3 => 1, 4 => 4],
        'moveWithinScope() must not survive the caller rolling back its own transaction',
    );
    echo "Boundary -> the caller's own rollBack() undid the participating move; moveWithinScope() never commits for the caller\n";

    // This parent exists even though method_id 8 does not have any rates yet.
    $firstPosition = $append(8, 5); // The runner owns this transaction and commits the insert.
    php_persistence_example_assert($firstPosition === 1, 'expected position 1 for the first shipping rate');

    php_persistence_example_assert($pdo->beginTransaction(), 'failed to begin the caller-owned append transaction');
    try {
        $secondPosition = $append(8, 6);
        php_persistence_example_assert($secondPosition === 2, 'expected position 2 for the second shipping rate');
        php_persistence_example_assert($pdo->inTransaction(), 'the append ended the caller transaction');
        php_persistence_example_assert($pdo->commit(), 'failed to commit the second shipping rate');
    } finally {
        if ($pdo->inTransaction()) {
            php_persistence_example_assert($pdo->rollBack(), 'failed to roll back the caller-owned append transaction');
        }
    }

    $newScopeOrders = array_values(php_persistence_example_read_orders($pdo, $quotedTable, 8));
    php_persistence_example_assert($newScopeOrders === [1, 2], 'expected both committed positions in the new scope');
    echo "Result   -> appended method_id = 8's first two rates at positions 1 and 2\n";
} finally {
    if (!$pdo->inTransaction()) {
        php_persistence_example_assert($pdo->exec("DROP TABLE IF EXISTS {$quotedTable}") !== false, 'failed to drop the ordering fixture table');
        if ($methodsCreated) {
            php_persistence_example_assert($pdo->exec("DROP TABLE IF EXISTS {$quotedMethods}") !== false, 'failed to drop the shipping-method fixture table');
        }
    }
}

echo "OK\n";
