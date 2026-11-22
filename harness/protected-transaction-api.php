<?php

declare(strict_types=1);

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;

require __DIR__ . '/vendor/autoload.php';

if (!class_exists(PdoProtectedTransactionCheckout::class)) {
    throw new RuntimeException('The installed package has no protected write checkout.');
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
$table = 'consumer_protected_' . bin2hex(random_bytes(6));
$observer->exec("CREATE TABLE $table (id INT PRIMARY KEY, display_order INT NOT NULL, KEY (display_order)) ENGINE=InnoDB");

try {
    $observer->exec("INSERT INTO $table VALUES (1, 1), (2, 2)");
    $catalog = new ProtectedTransactionCommandCatalog([
        'insert' => new ProtectedTransactionCommandPlan("INSERT INTO $table VALUES (:id, :position)", ['id', 'position']),
        'read' => new ProtectedTransactionCommandPlan("SELECT id FROM $table ORDER BY display_order", [], 3),
        'fail' => new ProtectedTransactionCommandPlan('SELECT missing_protected_consumer_column', [], 1),
    ]);
    $config = new ScopedOrderingConfig($table, deletedAtColumn: null);
    $ordering = new ProtectedScopedOrderingManager();
    $checkout = PdoProtectedTransactionCheckout::attachExclusive($connect(), $catalog);
    $checkout->begin();
    if (!$ordering->moveWithinScope($checkout, $config, null, 2, 1)) {
        throw new RuntimeException('Protected move failed.');
    }
    $next = $ordering->getNextPositionForUpdate($checkout, $config);
    $checkout->runRegisteredCommand('insert', ['id' => 3, 'position' => $next]);
    $outside = static function () use ($observer, $table): array {
        $statement = $observer->query("SELECT id FROM $table ORDER BY display_order");
        if ($statement === false) {
            throw new RuntimeException('Unable to observe the Host transaction.');
        }

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    };
    $inside = $checkout->runRegisteredCommand('read');
    if ($next !== 3 || !is_array($inside) || array_column($inside, 'id') !== [2, 1, 3]
        || $outside() !== [1, 2]
    ) {
        throw new RuntimeException('The protected operations did not share the Host transaction.');
    }
    $checkout->commit();
    if ($outside() !== [2, 1, 3] || $checkout->settlement()?->reason !== ProtectedTransactionReasonEnum::COMMITTED) {
        throw new RuntimeException('The Host commit was not confirmed.');
    }
    $pdo = $checkout->returnForHost();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('The returned Host connection is not idle.');
    }

    $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog);
    unset($pdo);
    $checkout->begin();
    try {
        $checkout->runRegisteredCommand('fail');
        throw new RuntimeException('Expected a real PDO exception.');
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1054 || $checkout->settlement()?->disposition !== ProtectedTransactionDispositionEnum::DISCARD_REQUIRED) {
            throw $error;
        }
    }
    try {
        $checkout->runRegisteredCommand('read');
        throw new RuntimeException('The failed Host connection was still usable.');
    } catch (ProtectedTransactionException) {
        $checkout->discardForHost();
    }
} finally {
    unset($checkout, $pdo);
    $observer->exec("DROP TABLE IF EXISTS $table");
}

echo "PROTECTED TRANSACTION PUBLIC API OK\n";
