<?php

declare(strict_types=1);

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $items, $anchor, $isolation, $pair, $receipt] = ($argv ?? []) + array_fill(0, 6, '');
if ($items === '' || !in_array($isolation, ['READ COMMITTED', 'REPEATABLE READ'], true)) {
    throw new InvalidArgumentException('Missing contender configuration.');
}
$decoded = json_decode($receipt, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($decoded)) {
    throw new InvalidArgumentException('Missing contender attestation.');
}
$attestation = [];
foreach ($decoded as $name => $value) {
    if (!is_string($name)) {
        throw new InvalidArgumentException('Invalid contender attestation field.');
    }
    $attestation[$name] = $value;
}
$config = new UniqueOrderingConfig(
    new ScopedOrderingConfig(table: $items, scopeColumn: 'scope_id', orderColumn: 'pos', deletedAtColumn: null),
    $anchor,
    'id',
    null,
    32,
    16,
    $attestation,
    'it-revision-1',
);
$pdo = new PDO(
    'mysql:host=' . getenv('PHP_PERSISTENCE_IT_HOST') . ';port=' . getenv('PHP_PERSISTENCE_IT_PORT')
    . ';dbname=' . getenv('PHP_PERSISTENCE_IT_DATABASE'),
    (string) getenv('PHP_PERSISTENCE_IT_USER'),
    (string) getenv('PHP_PERSISTENCE_IT_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
$quote = chr(96);
$checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
    'delete' => new ProtectedTransactionCommandPlan(
        'DELETE FROM ' . $config->ordering->quotedTable()
            . " WHERE {$quote}id{$quote} = :id AND {$quote}scope_id{$quote} = :scope_id AND {$quote}pos{$quote} = :position",
        ['id', 'scope_id', 'position'],
    ),
]));
unset($pdo);
$checkout->begin();
$manager = new ProtectedScopedOrderingManager();
try {
    match ($pair) {
        'move', 'delete-move' => $manager->moveUniqueWithinScope($checkout, $config, 7, 2, 1),
        'delete' => $manager->deleteUniqueWithinScope($checkout, $config, 7, 2, 'delete'),
        default => $manager->getNextUniquePositionForAppend($checkout, $config, 7),
    };
    throw new RuntimeException('The writer crossed a locked anchor.');
} catch (PDOException $failure) {
    if (!in_array($failure->errorInfo[1] ?? null, [1205, '1205'], true)
        || $checkout->settlement()?->disposition !== ProtectedTransactionDispositionEnum::DISCARD_REQUIRED
    ) {
        throw $failure;
    }
}
try {
    $checkout->commit();
    throw new RuntimeException('The timed-out checkout accepted another command.');
} catch (ProtectedTransactionException) {
    $checkout->discardForHost();
}
echo "1205 DISCARD_REQUIRED\n";
