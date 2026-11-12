<?php

declare(strict_types=1);

/**
 * Smoke-executes the runnable examples in examples/ against one real, disposable
 * MySQL/MariaDB service (entry point: composer examples:smoke).
 *
 * Reuses the same parameterized Compose definition, image matrix, and PDO-probe
 * primitives as the Integration orchestration (scripts/integration.php /
 * scripts/integration-support.php) instead of inventing a second database stack
 * or Compose contract, per the CI Workflow Standard §8 ("standalone example
 * smoke execution" / "CI MUST NOT create a new Docker Compose contract merely to
 * validate an example") and the Library Presentation Standard §10.2. It is a
 * separate, additive entry point so example validation can evolve without
 * touching the Integration suite that WU-6/7/8 behavior acceptance depends on.
 *
 * Usage: php scripts/examples-smoke.php [--cell=<key>]
 *   --cell   key of docker/integration-matrix.json (env PP_DB_CELL, default from the matrix)
 *
 * Exit status: 0 success, 1 an example failed, 2 invalid usage or configuration, 3 infrastructure failure.
 */

require __DIR__ . '/integration-support.php';

/** @var list<string> $argv */
$root = dirname(__DIR__);

/** Example files, in the order they are smoke-executed; each must be self-contained and drop its own table(s). */
$examples = [
    'transaction_basic_usage.php',
    'pagination_basic_usage.php',
    'ordering_basic_usage.php',
];

$requestedCell = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--cell=(.+)$/', $argument, $match) === 1) {
        $requestedCell = $match[1];

        continue;
    }
    fail(EXIT_USAGE, 'Unknown or invalid option: ' . $argument);
}

$matrix = loadMatrix($root);
secondsFromEnvironment('PP_UP_TIMEOUT_SECONDS', 1);
secondsFromEnvironment('PP_TEARDOWN_TIMEOUT_SECONDS', 1);
secondsFromEnvironment('PP_SUITE_TIMEOUT_SECONDS', 1);
startBudget();

$cellKey = $requestedCell
    ?? (getenv('PP_DB_CELL') !== false && getenv('PP_DB_CELL') !== '' ? (string) getenv('PP_DB_CELL') : $matrix['default']);
if (!array_key_exists($cellKey, $matrix['cells'])) {
    fail(EXIT_USAGE, 'Unknown cell "' . $cellKey . '". Allowed: ' . implode(', ', array_keys($matrix['cells'])));
}
$cell = $matrix['cells'][$cellKey];

foreach ([['docker', '--version'], ['docker', 'compose', 'version'], ['docker', 'info']] as $check) {
    [$status] = execute($check);
    if ($status !== 0) {
        fail(EXIT_INFRASTRUCTURE, 'Prerequisite failed: ' . implode(' ', $check) . ' (Docker with Compose and a running daemon are required for example smoke execution)');
    }
}
if (!extension_loaded('pdo_mysql')) {
    fail(EXIT_INFRASTRUCTURE, 'Prerequisite failed: ext-pdo_mysql is not loaded');
}
if (!is_file($root . '/vendor/autoload.php')) {
    fail(EXIT_INFRASTRUCTURE, 'Prerequisite failed: run composer install first');
}
foreach ($examples as $example) {
    if (!is_file($root . '/examples/' . $example)) {
        fail(EXIT_USAGE, 'Missing example file: examples/' . $example);
    }
}

/**
 * Starts one disposable service, runs every example against it, and returns the
 * exit status. The caller owns teardown.
 *
 * @param array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool} $cell
 * @param array<string, string> $environment
 * @param list<string> $compose
 * @param list<string> $exampleFiles
 */
function runExamples(string $root, string $cellKey, array $cell, array $environment, array $compose, array $exampleFiles): int
{
    [$upStatus, , $upError] = execute([...$compose, 'up', '--detach', '--wait', '--wait-timeout', (string) HEALTH_TIMEOUT_SECONDS], $environment, $root, boxed(secondsFromEnvironment('PP_UP_TIMEOUT_SECONDS', HEALTH_TIMEOUT_SECONDS + 420)));
    if ($upStatus !== 0) {
        fwrite(STDERR, "Service did not become healthy:\n" . $upError . "\n");

        return EXIT_INFRASTRUCTURE;
    }

    [$portStatus, $portOut] = execute([...$compose, 'port', 'database', '3306'], $environment, $root, boxed(COMMAND_TIMEOUT_SECONDS));
    if ($portStatus !== 0 || preg_match('/^(?:127\.0\.0\.1|\[::1\]|0\.0\.0\.0):(\d+)$/m', trim($portOut), $portMatch) !== 1) {
        fwrite(STDERR, 'Cannot discover the published loopback port: ' . $portOut . "\n");

        return EXIT_INFRASTRUCTURE;
    }

    $password = $environment['PP_DB_PASSWORD'];
    $suiteEnvironment = [
        'PHP_PERSISTENCE_IT_ENGINE' => $cell['engine'],
        'PHP_PERSISTENCE_IT_EXPECTED_VERSION' => $cell['expectedVersion'],
        'PHP_PERSISTENCE_IT_CELL' => $cellKey,
        'PHP_PERSISTENCE_IT_HOST' => '127.0.0.1',
        'PHP_PERSISTENCE_IT_PORT' => $portMatch[1],
        'PHP_PERSISTENCE_IT_DATABASE' => $environment['PP_DB_DATABASE'],
        'PHP_PERSISTENCE_IT_USER' => $environment['PP_DB_USER'],
        'PHP_PERSISTENCE_IT_PASSWORD' => $password,
    ];

    $deadline = time() + boxed(PROBE_TIMEOUT_SECONDS);
    $pdo = null;
    while ($pdo === null) {
        try {
            $pdo = connect($suiteEnvironment, $environment['PP_DB_USER'], $password);
            scalar($pdo, 'SELECT 1');
        } catch (PDOException $exception) {
            $pdo = null;
            if (time() >= $deadline) {
                fwrite(STDERR, 'PDO readiness probe failed: ' . $exception->getMessage() . "\n");

                return EXIT_INFRASTRUCTURE;
            }
            usleep(500_000);
        }
    }

    $version = scalar($pdo, 'SELECT VERSION()');
    echo 'SERVER-IDENTITY ' . json_encode(['cell' => $cellKey, 'selectVersion' => $version, 'hostPhp' => PHP_VERSION], JSON_THROW_ON_ERROR) . "\n";
    if (!str_starts_with($version, $cell['expectedVersion'])) {
        fwrite(STDERR, 'Server identity mismatch: SELECT VERSION() = ' . $version . ', expected prefix ' . $cell['expectedVersion'] . "\n");

        return EXIT_INFRASTRUCTURE;
    }

    foreach ($exampleFiles as $exampleFile) {
        if (remainingWorkSeconds() < 1) {
            fwrite(STDERR, "The overall time budget is exhausted; stopping before the next example.\n");

            return EXIT_INFRASTRUCTURE;
        }
        echo '--- examples/' . $exampleFile . "\n";
        $exampleStatus = passthrough([PHP_BINARY, $root . '/examples/' . $exampleFile], $suiteEnvironment, $root, boxed(secondsFromEnvironment('PP_SUITE_TIMEOUT_SECONDS', 900)));
        if ($exampleStatus !== 0) {
            fwrite(STDERR, 'examples/' . $exampleFile . ' exited with status ' . $exampleStatus . "\n");

            return EXIT_TEST_FAILURE;
        }
    }

    try {
        // The application user has no PROCESS privilege; the disposable root account inspects the server.
        $admin = connect($suiteEnvironment, 'root', $environment['PP_DB_ROOT_PASSWORD']);
        $residue = (int) scalar($admin, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '" . $environment['PP_DB_DATABASE'] . "'");
        $openTransactions = (int) scalar($admin, 'SELECT COUNT(*) FROM information_schema.innodb_trx');
    } catch (PDOException $exception) {
        fwrite(STDERR, 'Residue check failed: ' . $exception->getMessage() . "\n");

        return EXIT_INFRASTRUCTURE;
    }
    echo 'RESIDUE-CHECK tables=' . $residue . ' openTransactions=' . $openTransactions . "\n";
    if ($residue !== 0 || $openTransactions !== 0) {
        fwrite(STDERR, "The examples left database objects or transactions behind.\n");

        return EXIT_TEST_FAILURE;
    }

    return 0;
}

$project = 'phppersist-ex-' . bin2hex(random_bytes(4));
$environment = [
    'PP_COMPOSE_PROJECT' => $project,
    'PP_DB_IMAGE' => imageReference($cell),
    'PP_DB_ROOT_PASSWORD' => bin2hex(random_bytes(12)),
    'PP_DB_DATABASE' => 'php_persistence_examples',
    'PP_DB_USER' => 'pp_examples',
    'PP_DB_PASSWORD' => bin2hex(random_bytes(12)),
];
if ($cell['platform'] !== null) {
    $environment['DOCKER_DEFAULT_PLATFORM'] = $cell['platform'];
}
$compose = ['docker', 'compose', '--project-name', $project, '--file', $root . '/compose.integration.yaml'];

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function () use ($compose, $project, $environment, $root): void {
            teardown($compose, $project, $environment, $root);
            exit(130);
        });
    }
}

echo '--- examples smoke cell=' . $cellKey . ' image=' . $environment['PP_DB_IMAGE'] . ' project=' . $project . "\n";

$status = EXIT_INFRASTRUCTURE;
try {
    $status = runExamples($root, $cellKey, $cell, $environment, $compose, $examples);
} finally {
    $torndown = teardown($compose, $project, $environment, $root);
}

$overall = $status === 0 && !$torndown ? EXIT_INFRASTRUCTURE : $status;
echo $overall === 0 ? "EXAMPLES SMOKE OK cell={$cellKey}\n" : "EXAMPLES SMOKE FAILED cell={$cellKey} status={$overall}\n";
exit($overall);
