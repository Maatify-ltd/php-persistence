<?php

declare(strict_types=1);

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

$pdo = php_persistence_example_connect();
$table = '`' . php_persistence_example_table_name('dynamic_sort') . '`';
$pdo->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, status VARCHAR(16) NOT NULL, priority INT NOT NULL) ENGINE=InnoDB");

try {
    $pdo->exec("INSERT INTO {$table} (id, status, priority) VALUES (1, 'open', 20), (2, 'closed', 10), (3, 'open', 20), (4, 'open', 10), (5, 'closed', 20)");
    $config = new PaginationConfig(
        sortWhitelist: new SortWhitelist(['status' => 'o.status', 'priority' => 'o.priority', 'internal_row' => 'o.id']),
        defaultSortBy: 'status',
        defaultSortDirection: SortDirectionEnum::ASC,
        tieBreakerSortBy: 'internal_row',
        tieBreakerDirection: SortDirectionEnum::ASC,
    );

    // The application adapter maps a grid request to public keys and directions.
    $sorts = [['key' => 'status', 'direction' => 'asc'], ['key' => 'priority', 'direction' => 'desc']];
    $count = "SELECT COUNT(*) FROM {$table}";
    $query = new PdoPaginationQueryDescriptor($count, [], $count, [], "SELECT o.id FROM {$table} o", []);
    $paginator = new PdoPaginator();
    $observed = [];
    foreach ([1, 2, 3] as $pageNumber) {
        $result = $paginator->paginateWithDynamicSort(
            pdo: $pdo,
            query: $query,
            request: new PageRequest(page: $pageNumber, perPage: 2),
            config: $config,
            sorts: $sorts,
            mapper: static fn(array $row): array => ['id' => php_persistence_example_to_int($row['id'])],
        );
        $pagination = $result->toArray()['pagination'];
        $selected = [['key' => 'status', 'direction' => 'ASC'], ['key' => 'priority', 'direction' => 'DESC']];
        php_persistence_example_assert($pagination['selected_sorts'] === $selected, 'unexpected selected order');
        php_persistence_example_assert($pagination['effective_sorts'] === [...$selected, ['key' => null, 'direction' => 'ASC']], 'unexpected effective order or exposed tie label');
        php_persistence_example_assert($result->page->page === $pageNumber && $result->page->filtered === 5, 'unexpected page or count');
        array_push($observed, ...array_column($result->page->data, 'id'));
    }

    php_persistence_example_assert($observed === [5, 2, 1, 3, 4], 'unexpected dynamic order');
    echo "DYNAMIC SORT OK: status ASC, priority DESC, hidden tie ASC, ids 5,2,1,3,4\n";
} finally {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
