<?php

declare(strict_types=1);

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Config\StaticSortPlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

$pdo = php_persistence_example_connect();
$table = '`' . php_persistence_example_table_name('static_sort') . '`';
$pdo->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, status VARCHAR(16) NOT NULL, priority INT NOT NULL) ENGINE=InnoDB");

try {
    $pdo->exec("INSERT INTO {$table} (id, status, priority) VALUES (1, 'open', 20), (2, 'closed', 10), (3, 'open', 20), (4, 'open', 10), (5, 'closed', 20)");

    // Build once for this query shape. The Host owns column names and the final tie-breaker.
    $plan = new StaticSortPlan(
        config: new PaginationConfig(
            sortWhitelist: new SortWhitelist(['workflow' => 'status', 'id' => 'id']),
            defaultSortBy: 'workflow',
            defaultSortDirection: SortDirectionEnum::ASC,
            tieBreakerSortBy: 'id',
            tieBreakerDirection: SortDirectionEnum::ASC,
        ),
        profiles: [
            'workflow' => [
                ['identifier' => 'priority', 'direction' => SortDirectionEnum::DESC],
                ['identifier' => 'id', 'direction' => SortDirectionEnum::ASC],
            ],
            'id' => null,
        ],
    );

    $count = "SELECT COUNT(*) FROM {$table}";
    $query = new PdoPaginationQueryDescriptor($count, [], $count, [], "SELECT id FROM {$table}", []);
    $paginator = new PdoPaginator();
    $observed = [];
    foreach ([1, 2, 3] as $pageNumber) {
        $page = $paginator->paginateWithStaticSort(
            pdo: $pdo,
            query: $query,
            request: new PageRequest(page: $pageNumber, perPage: 2),
            plan: $plan,
            mapper: static fn(array $row): array => ['id' => php_persistence_example_to_int($row['id'])],
        );
        php_persistence_example_assert($page->sortBy === 'workflow' && $page->sortDirection === SortDirectionEnum::ASC, 'unexpected sort metadata');
        php_persistence_example_assert($page->page === $pageNumber && $page->filtered === 5, 'unexpected page or count');
        array_push($observed, ...array_column($page->data, 'id'));
    }

    php_persistence_example_assert($observed === [5, 2, 1, 3, 4], 'unexpected composite order');
    echo "STATIC SORT OK: workflow ASC, ids 5,2,1,3,4\n";
} finally {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
