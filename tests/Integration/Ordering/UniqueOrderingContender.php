<?php

declare(strict_types=1);

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\Ordering\B1CheckoutLifecycle;
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
$coordinated = in_array($pair, ['wait', 'deadlock'], true);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . ($coordinated ? '12' : '1'));
$identity = $pdo->query('SELECT CONNECTION_ID()');
$threadId = $identity === false ? false : $identity->fetchColumn();
if (!is_int($threadId) && !is_string($threadId)) {
    throw new RuntimeException('Unable to identify contender session.');
}
unset($identity);
$quote = chr(96);
$checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
    'anchor' => new ProtectedTransactionCommandPlan(
        'SELECT id FROM ' . $config->anchor->quotedTable() . ' WHERE id = :scope FOR UPDATE',
        ['scope'],
        maxRows: 1,
    ),
    'insert' => new ProtectedTransactionCommandPlan(
        'INSERT INTO ' . $config->ordering->quotedTable() . ' (id, scope_id, pos) VALUES (99, 7, :position)',
        ['position'],
    ),
    'delete' => new ProtectedTransactionCommandPlan(
        'DELETE FROM ' . $config->ordering->quotedTable()
            . " WHERE {$quote}id{$quote} = :id AND {$quote}scope_id{$quote} = :scope_id AND {$quote}pos{$quote} = :position",
        ['id', 'scope_id', 'position'],
    ),
]));
unset($pdo);
$lifecycle = new B1CheckoutLifecycle();
$lifecycle->track($checkout);
$primary = null;
try {
    $lifecycle->begin($checkout);
    $manager = new ProtectedScopedOrderingManager();
    if ($pair === 'deadlock') {
        $checkout->runRegisteredCommand('anchor', ['scope' => 8]);
    }
    if ($coordinated) {
        echo "READY $threadId\n";
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'START') {
            throw new RuntimeException('The waiter was not released.');
        }
    }
    if ($pair === 'wait') {
        $position = $manager->getNextUniquePositionForAppend($checkout, $config, 7);
        if ($checkout->runRegisteredCommand('insert', ['position' => $position]) !== 1) {
            throw new RuntimeException('The waiter did not insert its row.');
        }
        $lifecycle->commit($checkout);
        $result = "INSERTED $position\n";
    } else {
        $expected = $pair === 'deadlock' ? 1213 : 1205;
        try {
            match ($pair) {
                'move', 'delete-move' => $manager->moveUniqueWithinScope($checkout, $config, 7, 2, 1),
                'delete' => $manager->deleteUniqueWithinScope($checkout, $config, 7, 2, 'delete'),
                default => $manager->getNextUniquePositionForAppend($checkout, $config, 7),
            };
            throw new RuntimeException('The writer crossed a locked anchor.');
        } catch (PDOException $failure) {
            if (!in_array($failure->errorInfo[1] ?? null, [$expected, (string) $expected], true)
                || $checkout->settlement()?->disposition !== ProtectedTransactionDispositionEnum::DISCARD_REQUIRED
            ) {
                throw $failure;
            }
        }
        try {
            $checkout->commit();
            throw new RuntimeException('The fenced checkout accepted a commit request.');
        } catch (ProtectedTransactionException) {
            $lifecycle->discard($checkout);
        }
        $result = "$expected DISCARD_REQUIRED\n";
    }
} catch (Throwable $failure) {
    $primary = $failure;
    throw $failure;
} finally {
    $lifecycle->finish($primary);
}
echo $result;
