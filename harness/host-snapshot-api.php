<?php

declare(strict_types=1);

/** Exercises B using only a production Composer installation. */
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCommandPlan;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPagePlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandEffectEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandResultModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotRetirementDispositionEnum;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotPaginator;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;

require __DIR__ . '/vendor/autoload.php';

if (! class_exists(PdoHostSnapshotSource::class)) {
    throw new RuntimeException('The installed package has no Host snapshot B surface.');
}

$setting = static function (string $name): string {
    $value = getenv('PHP_PERSISTENCE_IT_' . $name);
    if ($value === false || $value === '') {
        throw new RuntimeException('Missing consumer database setting ' . $name);
    }

    return $value;
};
$dsn = 'mysql:host=' . $setting('HOST') . ';port=' . $setting('PORT') . ';dbname=' . $setting('DATABASE');
$connect = static fn(): PDO => new PDO($dsn, $setting('USER'), $setting('PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$observer = $connect();
$table = 'consumer_b_' . bin2hex(random_bytes(8));
$observer->exec("CREATE TABLE $table (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");

try {
    $observer->exec("INSERT INTO $table VALUES (1)");
    $config = new PaginationConfig(
        new SortWhitelist(['id' => 'id']),
        'id',
        SortDirectionEnum::Asc,
        'id',
        SortDirectionEnum::Asc,
        10,
        1,
        50,
    );

    $pagePlan = new HostSnapshotPagePlan(
        "SELECT COUNT(*) FROM $table",
        "SELECT COUNT(*) FROM $table",
        "SELECT id FROM $table",
        [],
        [],
        [],
        $config,
        static fn(array $row): array => $row,
    );
    $insert = new HostSnapshotCommandPlan(
        "INSERT INTO $table VALUES (:id)",
        ['id'],
        HostSnapshotCommandResultModeEnum::AffectedRows,
        effect: HostSnapshotCommandEffectEnum::PreservesTransaction,
    );
    $source = PdoHostSnapshotSource::attachExclusive($connect(), new HostSnapshotCatalog(['items' => $pagePlan], ['insert' => $insert]));
    $lease = $source->checkout();
    $lease->beginRepeatableRead();
    $lease->runRegisteredCommand('insert', ['id' => 2]);
    $attempt = $lease->newAttempt($lease->authorizePage('items', [], new PageRequest()));
    $page = new PdoHostSnapshotPaginator()->paginate($attempt);
    $readCommittedCount = static function () use ($observer, $table): int {
        $statement = $observer->query("SELECT COUNT(*) FROM $table");

        return (int) $statement->fetchColumn();
    };
    if ($page->total !== 2 || array_column($page->data, 'id') !== [1, 2]
        || $attempt->settlement()?->reason !== HostSnapshotPageSettlementReasonEnum::PageCompleted
        || $readCommittedCount() !== 1
    ) {
        throw new RuntimeException('The installed B surface failed its page or transaction contract.');
    }
    $lease->commit();
    $lease->release();
    $returned = $source->retireForHost();
    if ($readCommittedCount() !== 2 || $returned->pdo->inTransaction()
        || $returned->disposition !== HostSnapshotRetirementDispositionEnum::ReturnedIdle
        || $attempt->settlement()?->reason !== HostSnapshotPageSettlementReasonEnum::PageCompleted
    ) {
        throw new RuntimeException('The installed B surface failed its Host handoff or historical settlement.');
    }
} finally {
    unset($lease, $source, $returned);
    $observer->exec("DROP TABLE IF EXISTS $table");
}

echo "HOST SNAPSHOT PUBLIC API OK\n";
