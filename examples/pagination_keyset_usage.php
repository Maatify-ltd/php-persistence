<?php

declare(strict_types=1);

/**
 * Forward-only Keyset pagination on the Host's PDO. In a Composer project,
 * copy this file and _bootstrap.php to examples/ and run on a disposable database.
 */

use Maatify\PhpPersistence\Pagination\Criteria\KeysetQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Exception\InvalidKeysetCursorException;
use Maatify\PhpPersistence\Pagination\Repository\PdoKeysetPaginator;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

$pdo = php_persistence_example_connect();
$table = php_persistence_example_table_name('keyset');
$pdo->exec("CREATE TABLE `{$table}` (id BIGINT PRIMARY KEY, tenant_id INT NOT NULL) ENGINE=InnoDB");

$exampleFailure = null;
try {
    $pdo->exec("INSERT INTO `{$table}` VALUES (10,1), (20,1), (25,2), (30,1)");
    $query = new KeysetQueryDescriptor(
        queryId: 'example.items',
        revision: '1',
        table: $table,
        columns: ['id'],
        scopePredicate: 't.tenant_id = :tenant',
        parameterNames: ['tenant'],
    );
    // A real Host supplies a stable, deployment-managed keyring across requests.
    $pager = new PdoKeysetPaginator(
        activeKeyId: 'demo',
        keys: [['id' => 'demo', 'secret' => random_bytes(32)]],
        defaultLimit: 2,
        maxLimit: 20,
    );
    $scope = 'example-db:tenant:1';
    $bindings = ['tenant' => 1];
    $map = static fn(array $row): array => ['id' => php_persistence_example_to_int($row['id'])];

    $first = $pager->paginate(pdo: $pdo, query: $query, scope: $scope, bindings: $bindings, mapper: $map);
    $cursor = $first->nextCursor;
    if ($cursor === null) {
        throw new RuntimeException('Expected a cursor for a second page.');
    }
    php_persistence_example_assert($first->data === [['id' => 10], ['id' => 20]] && $first->hasNext, 'unexpected first page');

    $second = $pager->paginate(pdo: $pdo, query: $query, scope: $scope, bindings: $bindings, mapper: $map, cursor: $cursor, limit: 2);
    php_persistence_example_assert(
        json_encode($second, JSON_THROW_ON_ERROR) === '{"data":[{"id":30}],"hasNext":false,"nextCursor":null}',
        'unexpected continuation JSON',
    );

    try {
        $pager->paginate($pdo, $query, 'example-db:tenant:2', ['tenant' => 2], $map, $cursor);
        throw new RuntimeException('Expected the cursor to be rejected for another tenant.');
    } catch (InvalidKeysetCursorException) {
        // The Host resolves authorization and the corresponding scope on each request.
    }
} catch (Throwable $caught) {
    $exampleFailure = $caught;

    throw $caught;
} finally {
    try {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    } catch (Throwable $cleanupFailure) {
        if ($exampleFailure === null) {
            throw $cleanupFailure;
        }
    }
}

echo "KEYSET PAGINATION OK\n";
