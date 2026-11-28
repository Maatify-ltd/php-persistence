<?php

declare(strict_types=1);

/** Verified Owned and Host transactions against disposable InnoDB tables. */
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Command\VerifiedTransactionDecisionCommand as Decision;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionOutcomeEnum as Outcome;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Repository\PdoVerifiedTransactionCheckout as Checkout;
use Maatify\PhpPersistence\Transaction\Repository\VerifiedTransactionCommandsInterface as Commands;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_bootstrap.php';

$observer = php_persistence_example_connect();
$table = php_persistence_example_table_name('verified');
$observer->exec("CREATE TABLE $table (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB");
$catalog = new ProtectedTransactionCommandCatalog([
    'insert' => new ProtectedTransactionCommandPlan("INSERT INTO $table VALUES (:id, :value)", ['id', 'value']),
    'read' => new ProtectedTransactionCommandPlan("SELECT v FROM $table WHERE id = :id", ['id'], 1),
]);

$open = static function () use ($catalog): Checkout {
    // The Host keeps only the checkout, with no raw alias in circulation.
    $options = [
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
        Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
    ];
    $pdo = new PDO(
        'mysql:host=' . php_persistence_example_env('PHP_PERSISTENCE_IT_HOST')
            . ';port=' . php_persistence_example_env('PHP_PERSISTENCE_IT_PORT')
            . ';dbname=' . php_persistence_example_env('PHP_PERSISTENCE_IT_DATABASE'),
        php_persistence_example_env('PHP_PERSISTENCE_IT_USER'),
        php_persistence_example_env('PHP_PERSISTENCE_IT_PASSWORD'),
        $options,
    );

    return Checkout::attachExclusive($pdo, $catalog);
};

$work = static fn(int $id, int $limit): Closure
    => static function (Commands $commands) use ($id, $limit): Decision {
        $commands->runRegisteredCommand('insert', ['id' => $id, 'value' => 20]);
        $rows = $commands->runRegisteredCommand('read', ['id' => $id]);
        if (!is_array($rows) || !isset($rows[0]['v'])) {
            throw new LogicException('Expected the provisional write.');
        }

        return php_persistence_example_to_int($rows[0]['v']) <= $limit ? Decision::apply(false) : Decision::discard();
    };

$dispose = static function (Checkout $checkout): void {
    if ($checkout->settlement()->disposition === Disposition::Reusable) {
        $pdo = $checkout->returnForHost();
        php_persistence_example_assert(!$pdo->inTransaction(), 'Expected a finished connection.');
    } else {
        $checkout->discardForHost();
    }
};

$failure = null;
try {
    foreach ([2 => true, 3 => false] as $id => $apply) {
        $checkout = $open();
        try {
            $payload = $checkout->runOwned($work($id, $apply ? 20 : 19));
            php_persistence_example_assert($payload === ($apply ? false : null), 'Unexpected Owned payload.');
            $expected = $apply ? Outcome::Committed : Outcome::RolledBack;
            php_persistence_example_assert($checkout->settlement()->transactionOutcome === $expected, 'Unexpected Owned end.');
        } finally {
            $dispose($checkout);
        }
    }

    $checkout = $open();
    try {
        $checkout->beginHost();
        $checkout->runRegisteredCommand('insert', ['id' => 1, 'value' => 10]);
        $result = $checkout->runInHost($work(4, 19));
        php_persistence_example_assert($result === null, 'Unexpected Host payload.');
        $local = $checkout->settlement();
        php_persistence_example_assert($local->operationOutcome === Outcome::LocalUndone && $local->transactionOutcome === null, 'Expected only local undo.');
        $checkout->commit();
        php_persistence_example_assert($checkout->settlement()->operationOutcome === Outcome::LocalUndone, 'Local evidence changed.');
    } finally {
        $dispose($checkout);
    }

    $checkout = $open();
    $domain = new DomainException('Application denied the provisional write.');
    try {
        try {
            $checkout->runOwned(static function (Commands $commands) use ($domain): never {
                $commands->runRegisteredCommand('insert', ['id' => 5, 'value' => 20]);
                throw $domain;
            });
        } catch (DomainException $caught) {
            php_persistence_example_assert($caught === $domain, 'The original error must escape.');
            $snapshot = $checkout->settlement();
            php_persistence_example_assert($snapshot->operationReason === Reason::CallbackFailed
                && $snapshot->transactionOutcome === Outcome::RolledBack, 'Expected verified cleanup.');
        }
    } finally {
        $dispose($checkout);
    }

    $rows = php_persistence_example_require_statement($observer->query("SELECT v FROM $table ORDER BY id"), 'Expected committed rows.');
    $values = array_map(php_persistence_example_to_int(...), $rows->fetchAll(PDO::FETCH_COLUMN));
    $rows->closeCursor();
    php_persistence_example_assert($values === [10, 20], 'The observer must see only the Host marker and the Owned apply.');
    echo "VERIFIED TRANSACTION EXAMPLE OK\n";
} catch (Throwable $caught) {
    $failure = $caught;
    throw $caught;
} finally {
    try {
        $observer->exec("DROP TABLE IF EXISTS $table");
    } catch (Throwable $cleanup) {
        if ($failure === null) {
            throw $cleanup;
        }
    }
}
