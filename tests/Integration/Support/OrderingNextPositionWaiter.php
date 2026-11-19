<?php

declare(strict_types=1);

/**
 * Test-only CLI script that runs a second allocator against the scoped ordering table on its
 * own MySQL/MariaDB connection, reusing the same PHP_PERSISTENCE_IT_* connection settings as
 * the Integration suite (inherited from the parent process). Golden-captured from legacy
 * tests/Support/MySql/NextPositionWaiter.php (1f6a7aa, provenance gate recorded in the WU-8
 * pull request). The original one-wave scenarios reproduce the locking-read loop.
 * The observe mode loads Composer and calls the actual manager, with read barriers.
 *
 * It opens a transaction, takes a plain-read snapshot, then repeats a locking read
 * (SELECT ... ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE) for the given scope
 * until two consecutive reads agree, exactly as ScopedOrderingManager::getNextPositionForUpdate()
 * does. The call blocks while another connection holds the scope's last row; the script prints
 * its connection id before the call and, after it, either the returned position or the driver
 * error code of a PDOException (for example 1213 for a deadlock).
 *
 * Usage: php OrderingNextPositionWaiter.php <table> "<READ COMMITTED|REPEATABLE READ>" <scope> [heavy] [hold]
 *
 * Flags:
 * - heavy: first lock many rows of an unrelated scope, so InnoDB treats this transaction as the
 *   heavier one when it has to pick a deadlock victim.
 * - hold: keep the transaction (and its locks) open after reporting, until the parent closes
 *   this process's stdin.
 * - observe [<parent-table> <parent-id>]: call the actual allocator and pause after its
 *   first two reads so the parent can schedule two competing insert attempts.
 */

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\Support\ObservedTailStatement;

/** @return non-empty-string */
$setting = static function (string $name): string {
    $value = getenv('PHP_PERSISTENCE_IT_' . $name);
    if ($value === false || $value === '') {
        fwrite(STDERR, 'Missing PHP_PERSISTENCE_IT_' . $name . "\n");
        exit(2);
    }

    return $value;
};

$rawArguments = $_SERVER['argv'] ?? [];
$arguments = is_array($rawArguments) ? array_values(array_filter($rawArguments, 'is_string')) : [];
$table = $arguments[1] ?? '';
$isolationLevel = $arguments[2] ?? '';
$scope = $arguments[3] ?? '';
$flags = array_slice($arguments, 4);
$observe = ($flags[0] ?? null) === 'observe';
$parentTable = $flags[1] ?? '';
$parentId = $flags[2] ?? '';
$validFlags = $observe
    ? count($flags) === 1 || (count($flags) === 3 && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $parentTable) === 1 && preg_match('/\A[0-9]+\z/', $parentId) === 1)
    : array_diff($flags, ['heavy', 'hold']) === [];

if (
    $table === ''
    || !in_array($isolationLevel, ['READ COMMITTED', 'REPEATABLE READ'], true)
    || $scope === ''
    || !$validFlags
) {
    fwrite(STDERR, "Usage: OrderingNextPositionWaiter.php <table> \"<READ COMMITTED|REPEATABLE READ>\" <scope> [heavy] [hold] | [observe [<parent-table> <parent-id>]]\n");
    exit(2);
}

$quotedTable = '`' . $table . '`';
$dsn = 'mysql:host=' . $setting('HOST') . ';port=' . $setting('PORT') . ';dbname=' . $setting('DATABASE');
$pdo = new PDO($dsn, $setting('USER'), $setting('PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 10,
]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolationLevel);
$pdo->beginTransaction();

try {
    if ($observe) {
        require dirname(__DIR__, 3) . '/vendor/autoload.php';
        if ($parentTable !== '') {
            $parent = $pdo->prepare('SELECT id FROM ' . '`' . $parentTable . '`' . ' WHERE id = :id FOR UPDATE');
            if ($parent === false || !$parent->execute(['id' => $parentId]) || $parent->fetchColumn() === false || !$parent->closeCursor()) {
                throw new RuntimeException('The allocator could not lock its parent row.');
            }
        }

        if (!$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ObservedTailStatement::class])) {
            throw new RuntimeException('Unable to observe the allocator locking statement.');
        }
        $startedAt = hrtime(true);
        $position = (new ScopedOrderingManager())->getNextPositionForUpdate(
            $pdo,
            new ScopedOrderingConfig(table: $table, scopeColumn: 'scope_key'),
            $scope,
        );
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('The allocator ended the caller-owned transaction.');
        }
        fwrite(STDOUT, sprintf("POSITION %d %.3F\n", $position, (hrtime(true) - $startedAt) / 1_000_000));
        fflush(STDOUT);
        if (ObservedTailStatement::readCommand() === "END\n") {
            if (!$pdo->rollBack()) {
                throw new RuntimeException('Unable to end the measured caller-owned transaction.');
            }
            fwrite(STDOUT, "ENDED\n");
            fflush(STDOUT);
        }

        return;
    }

    $connectionId = $pdo->query('SELECT CONNECTION_ID()');
    $snapshot = $pdo->query('SELECT COUNT(*) FROM ' . $quotedTable);

    if ($connectionId === false || $snapshot === false) {
        throw new RuntimeException('Unable to prepare the waiting allocator connection.');
    }

    $snapshot->fetchColumn();

    if (in_array('heavy', $flags, true)) {
        $pdo->exec(
            'INSERT INTO ' . $quotedTable . ' (`scope_key`, `display_order`, `label`)
             WITH RECURSIVE `seq` (`n`) AS (SELECT 1 UNION ALL SELECT `n` + 1 FROM `seq` WHERE `n` < 400)
             SELECT \'heavy-scope\', `n`, \'heavy\' FROM `seq`',
        );
    }

    fwrite(STDOUT, 'CONNECTION_ID ' . $connectionId->fetchColumn() . "\n");
    fflush(STDOUT);

    try {
        $statement = $pdo->prepare(
            'SELECT `display_order` FROM ' . $quotedTable . '
              WHERE `scope_key` = :scope_value AND `deleted_at` IS NULL
              ORDER BY `display_order` DESC, `id` DESC
              LIMIT 1
              FOR UPDATE',
        );

        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the waiting allocator locking read.');
        }

        $previousTail = null;
        $position = null;

        while (true) {
            $statement->execute(['scope_value' => $scope]);
            $value = $statement->fetchColumn();
            $statement->closeCursor();
            $tail = $value === false ? 0 : (int) $value;

            if ($tail === $previousTail) {
                $position = $tail + 1;

                break;
            }

            $previousTail = $tail;
        }

        fwrite(STDOUT, 'POSITION ' . $position . "\n");
        fflush(STDOUT);

        if (in_array('hold', $flags, true)) {
            fgets(STDIN);
        }
    } catch (PDOException $exception) {
        $driverCode = $exception->errorInfo[1] ?? null;

        fwrite(STDOUT, 'FAILURE ' . (is_int($driverCode) ? $driverCode : 'unknown') . "\n");
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
