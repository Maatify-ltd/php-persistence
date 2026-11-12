<?php

declare(strict_types=1);

/**
 * A consumer-owned catalog workflow. Run from the clean Composer root created by the harness.
 */
use Composer\InstalledVersions;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig as LegacyOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager as LegacyOrdering;
use Maatify\Persistence\Pdo\Pagination\PageRequest as LegacyRequest;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig as LegacyPaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor as LegacyQuery;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator as LegacyPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum as LegacySort;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist as LegacyWhitelist;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner as LegacySourceSavepointRunner;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner as LegacySourceRunner;
use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner as LegacyReleasedSavepointRunner;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;

require __DIR__ . '/vendor/autoload.php';

function verifySame(mixed $expected, mixed $actual, string $description): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($description . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function consumerEnvironment(string $key): string
{
    $value = getenv('PHP_PERSISTENCE_IT_' . $key);
    if ($value === false || $value === '') {
        throw new RuntimeException('Missing database environment field: ' . $key);
    }

    return $value;
}

/**
 * A PDO connection that can coordinate a second real connection before a specific query.
 */
final class CatalogConnection extends PDO
{
    public ?Closure $beforePrepare = null;

    public function __construct()
    {
        parent::__construct(
            'mysql:host=' . consumerEnvironment('HOST') . ';port=' . consumerEnvironment('PORT') . ';dbname=' . consumerEnvironment('DATABASE'),
            consumerEnvironment('USER'),
            consumerEnvironment('PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ($this->beforePrepare)?->__invoke($query);

        return parent::prepare($query, $options);
    }
}

function createCatalog(PDO $pdo, string $table): void
{
    $pdo->exec("CREATE TABLE `{$table}` (
        id INT NOT NULL PRIMARY KEY,
        scope_id INT NULL,
        position INT NOT NULL,
        deleted_at DATETIME NULL,
        stamp VARCHAR(8) NOT NULL DEFAULT 'ready',
        CHECK (stamp <> 'blocked'),
        KEY scope_position (scope_id, position)
    ) ENGINE=InnoDB");
}

/** @return list<int> */
function catalogIds(PDO $pdo, string $table): array
{
    return array_map(intval(...), $pdo->query("SELECT id FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
}

/** @return array<string, mixed> */
function catalogPage(PDO $pdo, string $table, bool $legacy): array
{
    $total = "SELECT COUNT(*) FROM `{$table}`";
    $filtered = $total . ' WHERE deleted_at IS NULL';
    $data = "SELECT id, position FROM `{$table}` WHERE deleted_at IS NULL";
    $mapper = static fn(array $row): array => ['id' => (int) $row['id'], 'position' => (int) $row['position']];
    if ($legacy) {
        $paginator = new LegacyPaginator();
        $query = new LegacyQuery($total, [], $filtered, [], $data, []);
        $request = new LegacyRequest(page: '9999', perPage: '0', sortBy: 'invalid');
        $config = new LegacyPaginationConfig(new LegacyWhitelist(['position' => 'position', 'id' => 'id']), 'position', LegacySort::ASC, 'id', LegacySort::DESC);
    } else {
        $paginator = new PdoPaginator();
        $query = new PdoPaginationQueryDescriptor($total, [], $filtered, [], $data, []);
        $request = new PageRequest(page: '9999', perPage: '0', sortBy: 'invalid');
        $config = new PaginationConfig(new SortWhitelist(['position' => 'position', 'id' => 'id']), 'position', SortDirectionEnum::ASC, 'id', SortDirectionEnum::DESC);
    }
    $result = $paginator->paginate($pdo, $query, $request, $config, $mapper);
    verifySame($result->toArray(), json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), 'Pagination array and JSON agree');

    return $result->toArray();
}

/** @return array<string, mixed> */
function exerciseCatalog(CatalogConnection $pdo, string $table, bool $legacy): array
{
    createCatalog($pdo, $table);
    $writer = new CatalogConnection();
    try {
        $runner = $legacy ? new LegacySourceRunner($pdo) : new PdoTransactionRunner($pdo);
        $manager = $legacy ? new LegacyOrdering() : new ScopedOrderingManager();
        $config = $legacy
            ? new LegacyOrderingConfig(table: $table, scopeColumn: 'scope_id', orderColumn: 'position', nullableScope: true)
            : new ScopedOrderingConfig(table: $table, scopeColumn: 'scope_id', orderColumn: 'position', nullableScope: true);

        $pdo->exec("INSERT INTO `{$table}` (id, scope_id, position, deleted_at) VALUES (1, 7, 1, NULL), (2, 7, 2, NULL), (3, 7, 20, '2026-01-01'), (4, NULL, 1, NULL)");
        verifySame(3, $manager->getNextPosition($pdo, $config, 7), 'Plain allocation excludes deleted rows');
        verifySame(2, $manager->getNextPosition($pdo, $config, null), 'Nullable scope allocation');
        verifySame(true, $manager->rowExistsInScope($pdo, $config, null, 4), 'Nullable scope row exists');
        verifySame(false, $manager->rowExistsInScope($pdo, $config, 7, 3), 'Soft-deleted row is invisible');

        $position = $runner->run(static function () use ($pdo, $table, $manager, $config): int {
            $position = $manager->getNextPositionForUpdate($pdo, $config, 7);
            $insert = $pdo->prepare("INSERT INTO `{$table}` (id, scope_id, position, deleted_at) VALUES (5, 7, :position, NULL)");
            $insert->execute(['position' => $position]);

            return $position;
        });
        verifySame(3, $position, 'Locking allocation');
        verifySame([1, 2, 3, 4, 5], catalogIds($writer, $table), 'Owned transaction commits observable state');
        verifySame(true, $manager->moveWithinScope($pdo, $config, 7, 1, 99), 'Clamp above the scope maximum');
        verifySame(3, (int) $writer->query("SELECT position FROM `{$table}` WHERE id = 1")->fetchColumn(), 'Move persists the clamped position');
        verifySame(true, $manager->moveWithinScope($pdo, $config, 7, 1, 3), 'Move at current position succeeds');
        verifySame(false, $manager->moveWithinScope($pdo, $config, 7, 99, 2), 'Missing move reports false');

        $timestampedConfig = $legacy
            ? new LegacyOrderingConfig(table: $table, scopeColumn: 'scope_id', orderColumn: 'position', updatedAtColumn: 'stamp')
            : new ScopedOrderingConfig(table: $table, scopeColumn: 'scope_id', orderColumn: 'position', updatedAtColumn: 'stamp');
        $beforeMove = $pdo->query("SELECT id, position FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $pdo->beginTransaction();
        $pdo->exec("UPDATE `{$table}` SET stamp = 'earlier' WHERE id = 4");
        try {
            $manager->moveWithinScope($pdo, $timestampedConfig, 7, 1, 1, 'blocked');
            throw new LogicException('Target write unexpectedly passed its CHECK constraint');
        } catch (PDOException $error) {
            verifySame(3819, (int) ($error->errorInfo[1] ?? 0), 'Target write failed after the range shift');
        }
        verifySame($beforeMove, $pdo->query("SELECT id, position FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR), 'Ordering savepoint reversed the shift');
        verifySame(true, $pdo->inTransaction(), 'Ordering failure retained caller ownership');
        $pdo->commit();
        verifySame('earlier', $writer->query("SELECT stamp FROM `{$table}` WHERE id = 4")->fetchColumn(), 'Earlier caller write survives the failed move');

        $failure = new RuntimeException('Consumer abort');
        try {
            $runner->run(static function () use ($pdo, $table, $failure): never {
                $pdo->exec("INSERT INTO `{$table}` (id, scope_id, position, deleted_at) VALUES (6, 7, 4, NULL)");
                throw $failure;
            });
            throw new LogicException('Owned runner did not propagate the callback exception');
        } catch (RuntimeException $error) {
            verifySame($failure, $error, 'Primary callback exception identity');
        }
        verifySame([1, 2, 3, 4, 5], catalogIds($writer, $table), 'Owned transaction rolls back observable state');
        verifySame(false, $pdo->inTransaction(), 'Owned transaction closed');

        $page = catalogPage($pdo, $table, $legacy);
        verifySame([['id' => 4, 'position' => 1]], $page['data'], 'Normalized page applies deterministic tie-breaker');
        verifySame([
            'page' => 1, 'per_page' => 1, 'total' => 5, 'filtered' => 4,
            'total_pages' => 4, 'has_next' => true, 'has_previous' => false,
            'sort_by' => 'position', 'sort_direction' => 'ASC',
        ], $page['pagination'], 'Observable pagination envelope');

        $pdo->beforePrepare = static function (string $sql) use ($pdo, $writer, $table): void {
            if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'WHERE')) {
                $pdo->beforePrepare = null;
                $writer->exec("INSERT INTO `{$table}` (id, scope_id, position, deleted_at) VALUES (7, 7, 4, NULL)");
            }
        };
        // Start with five visible rows so the between-counts insert makes filtered > total.
        $pdo->exec("UPDATE `{$table}` SET deleted_at = NULL WHERE id = 3");
        $withoutSnapshot = catalogPage($pdo, $table, $legacy);
        verifySame(5, $withoutSnapshot['pagination']['total'], 'Total ran before the concurrent commit');
        verifySame(6, $withoutSnapshot['pagination']['filtered'], 'Filter observed the concurrent commit');
        verifySame(false, $pdo->inTransaction(), 'Paginator left autocommit intact');

        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $pdo->beforePrepare = static function (string $sql) use ($pdo, $writer, $table): void {
            if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'WHERE')) {
                $pdo->beforePrepare = null;
                $writer->exec("INSERT INTO `{$table}` (id, scope_id, position, deleted_at) VALUES (8, 7, 5, NULL)");
            }
        };
        $withSnapshot = catalogPage($pdo, $table, $legacy);
        verifySame(6, $withSnapshot['pagination']['total'], 'Caller snapshot total');
        verifySame(6, $withSnapshot['pagination']['filtered'], 'Caller snapshot filter');
        verifySame(true, $pdo->inTransaction(), 'Caller retained snapshot ownership');
        $pdo->rollBack();
        verifySame([1, 2, 3, 4, 5, 7, 8], catalogIds($writer, $table), 'Both independent commits persisted');

        return [$page, $withoutSnapshot, $withSnapshot];
    } finally {
        $pdo->beforePrepare = null;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("DROP TABLE `{$table}`");
    }
}

function exerciseSharedConnection(PDO $pdo, bool $legacySource): void
{
    $table = 'consumer_coexistence';
    createCatalog($pdo, $table);
    try {
        $candidate = new PdoSavepointTransactionRunner($pdo);
        $legacy = $legacySource ? new LegacySourceSavepointRunner($pdo) : new LegacyReleasedSavepointRunner($pdo);
        verifySame(false, is_a($candidate, $legacy::class), 'Candidate is a distinct type');
        verifySame(false, is_a($legacy, $candidate::class), 'Legacy is a distinct type');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO consumer_coexistence (id, scope_id, position, deleted_at) VALUES (1, NULL, 1, NULL)');
        $failure = new RuntimeException('Rollback the nested operation');
        $outerResult = $legacy->run(static function () use ($candidate, $pdo, $failure): string {
            $pdo->exec('INSERT INTO consumer_coexistence (id, scope_id, position, deleted_at) VALUES (2, NULL, 2, NULL)');
            try {
                $candidate->run(static function () use ($pdo, $failure): never {
                    $pdo->exec('INSERT INTO consumer_coexistence (id, scope_id, position, deleted_at) VALUES (3, NULL, 3, NULL)');
                    throw $failure;
                });
                throw new LogicException('Nested runner did not propagate the callback exception');
            } catch (RuntimeException $error) {
                verifySame($failure, $error, 'Nested candidate propagated the same exception');
            }
            verifySame([1, 2], catalogIds($pdo, 'consumer_coexistence'), 'Nested rollback retained caller and legacy work');

            return 'outer result';
        });
        verifySame('outer result', $outerResult, 'Legacy savepoint returns the callback result');
        verifySame(true, $pdo->inTransaction(), 'Outer transaction remains caller-owned');
        $pdo->rollBack();
        verifySame([], catalogIds($pdo, $table), 'Caller still decides whether to persist both packages’ work');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec("DROP TABLE `{$table}`");
    }
}

$scenario = $argv[1] ?? '';
if (!in_array($scenario, ['coexistence', 'differential'], true)) {
    throw new InvalidArgumentException('Unknown consumer scenario');
}
verifySame(true, InstalledVersions::isInstalled('maatify/php-persistence', false), 'Candidate installed via Composer');
verifySame(true, InstalledVersions::isInstalled('maatify/persistence', false), 'Legacy installed via Composer');
verifySame(false, InstalledVersions::isInstalled('phpunit/phpunit'), 'Consumer does not install package development dependencies');
verifySame(false, class_exists(Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase::class), 'Production autoload excludes package tests');
$pdo = new CatalogConnection();
exerciseSharedConnection($pdo, $scenario === 'differential');
$candidate = exerciseCatalog($pdo, 'consumer_candidate', false);
if ($scenario === 'differential') {
    $legacy = exerciseCatalog($pdo, 'consumer_legacy', true);
    verifySame($legacy, $candidate, 'Legacy baseline and candidate observable pagination results');
}
echo "CONSUMER WORKFLOW OK scenario={$scenario}\n";
