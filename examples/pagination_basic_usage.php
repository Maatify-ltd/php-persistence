<?php

declare(strict_types=1);

/**
 * Pagination capability: PdoPaginator::paginate().
 *
 * Demonstrates whitelist-sorted, bounded pagination over a filtered data set,
 * and the empty-result shortcut when the filter matches nothing.
 *
 * Run standalone: php examples/pagination_basic_usage.php
 * Requires a real MySQL/MariaDB connection; see examples/_bootstrap.php.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;

$pdo = php_persistence_example_connect();
$table = php_persistence_example_table_name('orders');
$quotedTable = '`' . $table . '`';

$pdo->exec(
    "CREATE TABLE {$quotedTable} ("
    . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
    . "status VARCHAR(16) NOT NULL)",
);

try {
    $insert = php_persistence_example_require_statement(
        $pdo->prepare("INSERT INTO {$quotedTable} (status) VALUES (:status)"),
        'failed to prepare the seed insert',
    );
    for ($i = 0; $i < 15; $i++) {
        $insert->execute(['status' => 'paid']);
    }
    for ($i = 0; $i < 10; $i++) {
        $insert->execute(['status' => 'pending']);
    }

    // Input: an untrusted request asking for page 2 of the "paid" orders, 10 per page.
    $request = new PageRequest(page: 2, perPage: 10, sortBy: 'id', sortDirection: 'ASC');

    $config = new PaginationConfig(
        sortWhitelist: new SortWhitelist(['id' => 'id', 'status' => 'status']),
        defaultSortBy: 'id',
        defaultSortDirection: SortDirectionEnum::ASC,
        tieBreakerSortBy: 'id',
        tieBreakerDirection: SortDirectionEnum::ASC,
    );

    $query = new PdoPaginationQueryDescriptor(
        totalSql: "SELECT COUNT(*) FROM {$quotedTable}",
        totalParams: [],
        filteredCountSql: "SELECT COUNT(*) FROM {$quotedTable} WHERE status = :status",
        filteredCountParams: ['status' => 'paid'],
        dataSql: "SELECT id, status FROM {$quotedTable} WHERE status = :status",
        dataParams: ['status' => 'paid'],
    );

    echo "Input -> page 2, perPage 10, filter status = paid (15 of 25 rows match)\n";

    // Public Call
    $page = (new PdoPaginator())->paginate(
        $pdo,
        $query,
        $request,
        $config,
        static fn(array $row): array => [
            'id' => php_persistence_example_to_int($row['id']),
            'status' => php_persistence_example_to_string($row['status']),
        ],
    );

    // Result
    php_persistence_example_assert($page->total === 25, 'unexpected total count');
    php_persistence_example_assert($page->filtered === 15, 'unexpected filtered count');
    php_persistence_example_assert($page->totalPages === 2, 'unexpected total pages for 15 rows at 10 per page');
    php_persistence_example_assert($page->page === 2, 'unexpected current page');
    php_persistence_example_assert(count($page->data) === 5, 'unexpected row count on the last page');
    php_persistence_example_assert($page->hasNext === false, 'page 2 of 2 must not report hasNext');
    php_persistence_example_assert($page->hasPrevious === true, 'page 2 of 2 must report hasPrevious');
    echo 'Result   -> ' . json_encode($page->toArray()['pagination']) . "\n";

    // Boundary: a filter matching nothing short-circuits to an empty page 1 without
    // running the data query at all.
    $emptyQuery = new PdoPaginationQueryDescriptor(
        totalSql: "SELECT COUNT(*) FROM {$quotedTable}",
        totalParams: [],
        filteredCountSql: "SELECT COUNT(*) FROM {$quotedTable} WHERE status = :status",
        filteredCountParams: ['status' => 'cancelled'],
        dataSql: "SELECT id, status FROM {$quotedTable} WHERE status = :status",
        dataParams: ['status' => 'cancelled'],
    );
    $emptyPage = (new PdoPaginator())->paginate(
        $pdo,
        $emptyQuery,
        new PageRequest(),
        $config,
        static fn(array $row): array => $row,
    );
    php_persistence_example_assert($emptyPage->data === [], 'an empty filter must return no rows');
    php_persistence_example_assert($emptyPage->page === 1, 'an empty filter must report page 1');
    php_persistence_example_assert($emptyPage->totalPages === 0, 'an empty filter must report zero total pages');
    echo "Boundary -> status = cancelled (no rows) short-circuits to an empty page 1\n";
} finally {
    $pdo->exec("DROP TABLE IF EXISTS {$quotedTable}");
}

echo "OK\n";
