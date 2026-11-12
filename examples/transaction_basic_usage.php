<?php

declare(strict_types=1);

/**
 * Transaction capability: PdoTransactionRunner.
 *
 * Demonstrates both outcomes of run():
 * - a package-owned transaction that commits and returns the callback's result;
 * - a package-owned transaction that fails and is rolled back, with the
 *   callback's own exception rethrown unchanged.
 *
 * Run standalone: php examples/transaction_basic_usage.php
 * Requires a real MySQL/MariaDB connection; see examples/_bootstrap.php.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

use Maatify\PhpPersistence\Transaction\Exception\InvalidTransactionConfigurationException;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;

$pdo = php_persistence_example_connect();
$table = php_persistence_example_table_name('invoices');
$quotedTable = '`' . $table . '`';

$pdo->exec("CREATE TABLE {$quotedTable} (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, total_cents INT NOT NULL) ENGINE=InnoDB");

try {
    // Input: no active transaction on $pdo yet, so the runner will own one.
    $runner = new PdoTransactionRunner($pdo);

    echo "Input -> PdoTransactionRunner::run() with no active transaction (owns one)\n";

    // Public Call
    $invoiceId = $runner->run(static function () use ($pdo, $quotedTable): int {
        $statement = php_persistence_example_require_statement(
            $pdo->prepare("INSERT INTO {$quotedTable} (total_cents) VALUES (:total)"),
            'failed to prepare the insert statement',
        );
        $statement->execute(['total' => 1999]);

        return (int) $pdo->lastInsertId();
    });

    // Result: the committed row is visible because run() already committed it.
    $countStatement = php_persistence_example_require_statement(
        $pdo->query("SELECT COUNT(*) FROM {$quotedTable} WHERE id = {$invoiceId}"),
        'failed to run the verification query',
    );
    php_persistence_example_assert(!$pdo->inTransaction(), 'the runner left its owned transaction open');
    $persisted = (int) $countStatement->fetchColumn();
    php_persistence_example_assert($persisted === 1, 'the committed invoice row is not visible after run() returned');
    echo "Result   -> committed invoice id={$invoiceId}, visible outside the callback\n";

    // Boundary: a callback failure rolls back the whole owned transaction and the
    // original Throwable is rethrown unchanged; no row is persisted.
    echo "Input -> a second run() whose callback throws after inserting a row\n";
    $failure = new RuntimeException('simulated failure after an uncommitted insert');
    $rolledBack = false;

    try {
        $runner->run(static function () use ($pdo, $quotedTable, $failure): never {
            $statement = php_persistence_example_require_statement(
                $pdo->prepare("INSERT INTO {$quotedTable} (total_cents) VALUES (:total)"),
                'failed to prepare the insert statement',
            );
            $statement->execute(['total' => 500]);

            throw $failure;
        });
    } catch (RuntimeException $exception) {
        $rolledBack = $exception === $failure;
    }

    php_persistence_example_assert($rolledBack, 'the callback exception did not propagate unchanged');

    $totalRows = php_persistence_example_require_statement(
        $pdo->query("SELECT COUNT(*) FROM {$quotedTable}"),
        'failed to run the row-count verification query',
    )->fetchColumn();
    php_persistence_example_assert((int) $totalRows === 1, 'the failed callback was not fully rolled back');
    echo "Boundary -> the second insert was rolled back; only the first committed row remains\n";

    // Boundary: the configuration-validation failure of the constructor itself.
    $rejected = false;

    try {
        new PdoTransactionRunner($pdo, maxDeadlockRetries: 4);
    } catch (InvalidTransactionConfigurationException) {
        $rejected = true;
    }

    php_persistence_example_assert($rejected, 'an out-of-range deadlock retry limit was accepted');
    echo "Boundary -> maxDeadlockRetries: 4 was rejected with InvalidTransactionConfigurationException\n";
} finally {
    if ($pdo->inTransaction() && !$pdo->rollBack()) {
        throw new RuntimeException('Could not roll back the fixture transaction before dropping its table');
    }
    $pdo->exec("DROP TABLE IF EXISTS {$quotedTable}");
}

echo "OK\n";
