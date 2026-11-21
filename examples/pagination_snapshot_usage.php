<?php

declare(strict_types=1);

/**
 * C1 snapshot pagination against a disposable database. In a Composer project,
 * copy this file and _bootstrap.php to examples/ and run this example there.
 */
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoSnapshotPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

$writer = php_persistence_example_connect();
$table = php_persistence_example_table_name('snapshot');
$writer->exec("CREATE TABLE `{$table}` (id INT PRIMARY KEY, visible TINYINT NOT NULL) ENGINE=InnoDB");

$exampleFailure = null;
try {
    $writer->exec("INSERT INTO `{$table}` VALUES (1, 1), (2, 0)");
    $query = new PdoPaginationQueryDescriptor(
        "SELECT COUNT(*) FROM `{$table}`",
        [],
        "SELECT COUNT(*) FROM `{$table}` WHERE visible = :visible",
        ['visible' => 1],
        "SELECT id FROM `{$table}` WHERE visible = :visible",
        ['visible' => 1],
    );
    $config = new PaginationConfig(
        new SortWhitelist(['id' => 'id']),
        'id',
        SortDirectionEnum::ASC,
        'id',
        SortDirectionEnum::ASC,
    );

    $mapperFailure = new RuntimeException('Example mapper failure.');
    foreach ([null, $mapperFailure] as $expectedFailure) {
        // The Host provisions a fresh, non-persistent reader with server autocommit
        // enabled and REPEATABLE READ. The integration fixtures have those defaults.
        $reader = php_persistence_example_connect();
        $operation = PdoSnapshotPaginator::forExclusiveRead($reader);
        $page = null;
        $failure = null;
        try {
            $page = $operation->paginate(
                $query,
                new PageRequest(),
                $config,
                static function (array $row) use ($expectedFailure): array {
                    if ($expectedFailure !== null) {
                        throw $expectedFailure;
                    }

                    return ['id' => php_persistence_example_to_int($row['id'])];
                },
            );
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        // This example's Host keeps an eligible reader only on confirmed reuse.
        // For any other outcome it retires its reference to the fresh reader.
        if ($operation->connectionOutcome()?->disposition !== SnapshotConnectionDispositionEnum::REUSABLE) {
            $reader = null;
        }
        if ($failure !== null && $failure !== $expectedFailure) {
            throw $failure;
        }
        php_persistence_example_assert($failure === $expectedFailure, 'the mapper failure must retain its identity');
        if ($expectedFailure === null) {
            php_persistence_example_assert($page?->total === 2 && $page->filtered === 1 && $page->data === [['id' => 1]], 'unexpected snapshot page');
        }
        if ($reader === null) {
            throw new RuntimeException('Expected the Host to be able to reuse this reader.');
        }
        php_persistence_example_assert(! $reader->inTransaction(), 'the owned read must be finished before reuse');
        $probe = php_persistence_example_require_statement($reader->query('SELECT 1'), 'the settled Host reader is unavailable');
        php_persistence_example_assert(php_persistence_example_to_int($probe->fetchColumn()) === 1, 'unexpected reuse check');
        php_persistence_example_assert($probe->closeCursor(), 'failed to release the reuse check');
    }
} catch (Throwable $caught) {
    $exampleFailure = $caught;

    throw $caught;
} finally {
    try {
        $writer->exec("DROP TABLE IF EXISTS `{$table}`");
    } catch (Throwable $cleanupFailure) {
        if ($exampleFailure === null) {
            throw $cleanupFailure;
        }
    }
}

echo "C1 SNAPSHOT PAGINATION OK\n";
