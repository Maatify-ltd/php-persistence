<?php

declare(strict_types=1);

/**
 * Functions of the Integration orchestration (see integration.php).
 * Kept apart from the entry point so the regression suite can exercise them without Docker.
 */

const EXIT_TEST_FAILURE = 1;
const EXIT_USAGE = 2;
const EXIT_INFRASTRUCTURE = 3;
const HEALTH_TIMEOUT_SECONDS = 240;
const PROBE_TIMEOUT_SECONDS = 120;
const COMMAND_TIMEOUT_SECONDS = 120;
const TEARDOWN_TIMEOUT_SECONDS = 180;
const TOTAL_BUDGET_SECONDS = 1500;
const TEARDOWN_MARGIN_SECONDS = 30;
const TIMEOUT_EXIT_STATUS = 124;
const TERMINATION_GRACE_SECONDS = 5;

/**
 * A catchable signal in consumer verification. It unwinds the workspace before Compose teardown.
 */
final class ConsumerVerificationInterrupted extends Exception {}

/**
 * Positive integer seconds from an environment variable, or the default when it is not set.
 * A set but invalid value is a configuration error.
 */
function secondsFromEnvironment(string $name, int $default): int
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    if (preg_match('/^[1-9][0-9]{0,3}$/', $value) !== 1) {
        fail(EXIT_USAGE, $name . ' must be a whole number of seconds between 1 and 9999');
    }

    return (int) $value;
}

/**
 * Seconds granted to the whole teardown (all its commands together); also the core of the time
 * reserved for cleanup in the overall budget.
 */
function teardownSeconds(): int
{
    return secondsFromEnvironment('PP_TEARDOWN_TIMEOUT_SECONDS', TEARDOWN_TIMEOUT_SECONDS);
}

/**
 * Time that the overall budget always keeps free for teardown and its verification.
 */
function reserveSeconds(): int
{
    return teardownSeconds() + TEARDOWN_MARGIN_SECONDS;
}

/**
 * Starts the overall budget of this orchestration run. All work (startup, probes, suites,
 * helper commands) must end early enough to leave reserveSeconds() for teardown, so the
 * whole run finishes inside the budget that the CI job timeout is sized for.
 */
function startBudget(): void
{
    $budget = secondsFromEnvironment('PP_TOTAL_BUDGET_SECONDS', TOTAL_BUDGET_SECONDS);
    if ($budget <= reserveSeconds()) {
        fail(EXIT_USAGE, 'PP_TOTAL_BUDGET_SECONDS must exceed the teardown reserve of ' . reserveSeconds() . ' seconds');
    }
    OrchestrationBudget::$deadline = microtime(true) + $budget;
}

/**
 * Absolute end of the overall budget; null until startBudget() ran.
 */
final class OrchestrationBudget
{
    public static ?float $deadline = null;
}

/**
 * Whole seconds of work that still fit in the budget once the teardown reserve is kept free.
 */
function remainingWorkSeconds(): int
{
    $deadline = OrchestrationBudget::$deadline;
    if ($deadline === null) {
        return PHP_INT_MAX;
    }

    return (int) floor($deadline - microtime(true)) - reserveSeconds();
}

/**
 * Limits a command's own timeout to what the overall budget still allows (at least one second).
 */
function boxed(int $ownSeconds): int
{
    return max(1, min($ownSeconds, remainingWorkSeconds()));
}


/**
 * Computes a suite's absolute deadline without extending the overall budget.
 * Returns null when fewer than one whole second remains for work before teardown.
 */
function suiteDeadline(?float $overallDeadline, float $now, int $suiteTimeout, int $reserve): ?float
{
    $deadline = $now + $suiteTimeout + $reserve;
    if ($overallDeadline !== null) {
        $deadline = min($deadline, $overallDeadline);
    }

    return $deadline - $now - $reserve >= 1 ? $deadline : null;
}

/**
 * Stops a process that exceeded its deadline: SIGTERM, a short grace period, then SIGKILL.
 *
 * @param resource $process
 */
function terminateProcess($process): void
{
    proc_terminate($process);
    $until = microtime(true) + TERMINATION_GRACE_SECONDS;
    while (microtime(true) < $until) {
        if (proc_get_status($process)['running'] === false) {
            return;
        }
        usleep(100_000);
    }
    proc_terminate($process, 9);
}

/**
 * Runs a command without a shell and returns its exit status, standard output and standard error.
 * Both pipes are drained concurrently, so a process that writes a lot to either stream cannot
 * block on a full pipe while this function waits on the other one.
 *
 * The command is stopped when it exceeds $timeoutSeconds; the status is then TIMEOUT_EXIT_STATUS
 * and the standard error ends with a timeout notice.
 *
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{int, string, string} exit status, standard output, standard error
 */
function execute(array $command, array $environment = [], ?string $cwd = null, int $timeoutSeconds = COMMAND_TIMEOUT_SECONDS): array
{
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $environment === [] ? null : [...getenv(), ...$environment],
    );
    if (!is_resource($process)) {
        return [127, '', 'cannot start ' . $command[0]];
    }

    $buffers = [1 => '', 2 => ''];
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    foreach ($open as $stream) {
        stream_set_blocking($stream, false);
    }
    $deadline = microtime(true) + $timeoutSeconds;
    try {
        while ($open !== []) {
            if (microtime(true) >= $deadline) {
                terminateProcess($process);
                foreach ($open as $stream) {
                    fclose($stream);
                }
                proc_close($process);

                return [TIMEOUT_EXIT_STATUS, $buffers[1], $buffers[2] . "\n" . $command[0] . ' timed out after ' . $timeoutSeconds . " seconds\n"];
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($open as $index => $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $buffers[$index] .= $chunk;
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$index]);
                }
            }
        }
    } catch (ConsumerVerificationInterrupted $exception) {
        terminateProcess($process);
        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        proc_close($process);

        throw $exception;
    }

    return [proc_close($process), $buffers[1], $buffers[2]];
}

/**
 * Runs a command attached to this process's streams and returns its exit status, or
 * TIMEOUT_EXIT_STATUS when it exceeded $timeoutSeconds and was stopped.
 *
 * @param list<string> $command
 * @param array<string, string> $environment
 */
function passthrough(array $command, array $environment, string $cwd, int $timeoutSeconds): int
{
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd, [...getenv(), ...$environment]);
    if (!is_resource($process)) {
        return 127;
    }
    $deadline = microtime(true) + $timeoutSeconds;
    try {
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $code = proc_close($process);

                return $code === -1 ? $status['exitcode'] : $code;
            }
            if (microtime(true) >= $deadline) {
                terminateProcess($process);
                proc_close($process);
                fwrite(STDERR, $command[0] . ' timed out after ' . $timeoutSeconds . " seconds\n");

                return TIMEOUT_EXIT_STATUS;
            }
            usleep(100_000);
        }
    } catch (ConsumerVerificationInterrupted $exception) {
        if (is_resource($process)) {
            terminateProcess($process);
            proc_close($process);
        }

        throw $exception;
    }
}

function fail(int $code, string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * @return array{default: string, cells: array<string, array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool}>}
 */
function loadMatrix(string $root): array
{
    $json = file_get_contents($root . '/docker/integration-matrix.json');
    if ($json === false) {
        fail(EXIT_USAGE, 'docker/integration-matrix.json is missing');
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['default'], $data['cells']) || !is_string($data['default']) || !is_array($data['cells'])) {
        fail(EXIT_USAGE, 'docker/integration-matrix.json has an invalid shape');
    }

    /** @var array{default: string, cells: array<string, array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool}>} $data */
    return $data;
}

/**
 * @param array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool} $cell
 */
function imageReference(array $cell): string
{
    return $cell['image'] . ':' . $cell['tag'] . '@' . $cell['digest'];
}

/**
 * @param list<string> $arguments
 * @return array{cell: ?string, freshRuns: int, suitePasses: int}
 */
function parseOptions(array $arguments): array
{
    $options = ['cell' => null, 'freshRuns' => 1, 'suitePasses' => 2];
    foreach ($arguments as $argument) {
        if (preg_match('/^--cell=(.+)$/', $argument, $match) === 1) {
            $options['cell'] = $match[1];
        } elseif (preg_match('/^--fresh-runs=([1-9][0-9]?)$/', $argument, $match) === 1) {
            $options['freshRuns'] = (int) $match[1];
        } elseif (preg_match('/^--suite-passes=([1-9][0-9]?)$/', $argument, $match) === 1) {
            $options['suitePasses'] = (int) $match[1];
        } else {
            fail(EXIT_USAGE, 'Unknown or invalid option: ' . $argument);
        }
    }

    return $options;
}

/**
 * Stops the project's service and proves that nothing of it remains.
 *
 * The whole teardown (the `down` attempts and the three resource queries together) shares one
 * deadline of teardownSeconds(), so the time reserved for it in the overall budget is a real
 * upper bound: a slow or hung Docker cannot stretch it command by command. `down` is retried
 * once when time allows; anything that could not be checked in time counts as not verified.
 *
 * @param list<string> $compose docker compose prefix including the project and file
 * @param array<string, string> $environment
 */
function teardown(array $compose, string $project, array $environment, string $root): bool
{
    $end = microtime(true) + teardownSeconds();
    $left = static fn(): int => max(0, (int) floor($end - microtime(true)));

    $down = [...$compose, 'down', '--volumes', '--remove-orphans', '--timeout', '30'];
    [$downStatus, , $downError] = execute($down, $environment, $root, max(1, $left()));
    if ($downStatus !== 0 && $left() >= 5) {
        // One retry: a transient daemon error should not leave the project behind.
        [$downStatus, , $downError] = execute($down, $environment, $root, $left());
    }
    $label = 'label=com.docker.compose.project=' . $project;
    $remaining = [];
    if ($downStatus !== 0) {
        $remaining[] = 'docker compose down failed with status ' . $downStatus . ': ' . trim($downError);
    }
    foreach ([['ps', '-aq'], ['volume', 'ls', '-q'], ['network', 'ls', '-q']] as $query) {
        if ($left() < 1) {
            $remaining[] = implode(' ', $query) . ' not checked (teardown time exhausted)';

            continue;
        }
        [$status, $out] = execute(['docker', ...$query, '--filter', $label], [], null, $left());
        if ($status !== 0 || trim($out) !== '') {
            $remaining[] = implode(' ', $query);
        }
    }
    if ($remaining !== []) {
        fwrite(STDERR, 'Teardown incomplete for project ' . $project . ': ' . implode('; ', $remaining) . "\n");

        return false;
    }
    echo 'TEARDOWN VERIFIED project=' . $project . " containers=0 volumes=0 networks=0\n";

    return true;
}

function scalar(PDO $pdo, string $sql): string
{
    $statement = $pdo->query($sql);
    $value = $statement === false ? false : $statement->fetchColumn();

    return is_scalar($value) ? (string) $value : '';
}

function globalVariable(PDO $pdo, string $name): string
{
    $statement = $pdo->query("SHOW GLOBAL VARIABLES LIKE '" . $name . "'");
    $row = $statement === false ? false : $statement->fetch(PDO::FETCH_NUM);

    return is_array($row) && isset($row[1]) && is_scalar($row[1]) ? (string) $row[1] : 'absent';
}

/**
 * @param array<string, string> $suiteEnvironment
 */
function connect(array $suiteEnvironment, string $user, string $password): PDO
{
    return new PDO(
        'mysql:host=' . $suiteEnvironment['PHP_PERSISTENCE_IT_HOST'] . ';port=' . $suiteEnvironment['PHP_PERSISTENCE_IT_PORT']
            . ';dbname=' . $suiteEnvironment['PHP_PERSISTENCE_IT_DATABASE'],
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
}

/**
 * Starts the service, waits for readiness, runs the suite passes and returns the exit status.
 * The caller owns teardown.
 *
 * @param array<string, string> $environment
 * @param list<string> $compose
 * @param array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool} $cell
 * @param array{cell: ?string, freshRuns: int, suitePasses: int} $options
 * @param (callable(array<string, string>): int)|null $verification Optional external verification, with the same service lifecycle and residue checks.
 */
function runOnService(string $root, string $cellKey, array $cell, array $options, array $environment, array $compose, ?callable $verification = null): int
{
    [$upStatus, , $upError] = execute([...$compose, 'up', '--detach', '--wait', '--wait-timeout', (string) HEALTH_TIMEOUT_SECONDS], $environment, $root, boxed(secondsFromEnvironment('PP_UP_TIMEOUT_SECONDS', HEALTH_TIMEOUT_SECONDS + 420)));
    if ($upStatus !== 0) {
        fwrite(STDERR, "Service did not become healthy:\n" . $upError . "\n");
        [, $logs] = execute([...$compose, 'logs', '--tail', '40'], $environment, $root, boxed(COMMAND_TIMEOUT_SECONDS));
        fwrite(STDERR, $logs . "\n");

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

    $image = $environment['PP_DB_IMAGE'];
    $version = scalar($pdo, 'SELECT VERSION()');
    $comment = scalar($pdo, 'SELECT @@version_comment');
    [, $digestOut] = execute(['docker', 'image', 'inspect', $image, '--format', '{{index .RepoDigests 0}} {{.Architecture}}'], [], null, boxed(COMMAND_TIMEOUT_SECONDS));
    echo 'SERVER-IDENTITY ' . json_encode([
        'cell' => $cellKey,
        'image' => $image,
        'imageInspect' => trim($digestOut),
        'selectVersion' => $version,
        'versionComment' => $comment,
        'snapshotIsolationDefault' => globalVariable($pdo, 'innodb_snapshot_isolation'),
        'hostPhp' => PHP_VERSION,
    ], JSON_THROW_ON_ERROR) . "\n";
    if (!str_starts_with($version, $cell['expectedVersion'])) {
        fwrite(STDERR, 'Server identity mismatch: SELECT VERSION() = ' . $version . ', expected prefix ' . $cell['expectedVersion'] . "\n");

        return EXIT_INFRASTRUCTURE;
    }

    $phpunit = [PHP_BINARY, $root . '/vendor/bin/phpunit', '--testsuite', 'integration', '--no-progress'];
    for ($pass = 1; $pass <= $options['suitePasses']; $pass++) {
        if (remainingWorkSeconds() < 1) {
            fwrite(STDERR, "The overall time budget is exhausted; stopping before the next suite pass.\n");

            return EXIT_INFRASTRUCTURE;
        }
        echo '--- suite pass ' . $pass . '/' . $options['suitePasses'] . "\n";
        $result = $verification === null
            ? passthrough($phpunit, $suiteEnvironment, $root, boxed(secondsFromEnvironment('PP_SUITE_TIMEOUT_SECONDS', 900)))
            : $verification($suiteEnvironment);
        if ($result !== 0) {
            return $verification === null ? EXIT_TEST_FAILURE : $result;
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
        echo 'RESIDUE-CHECK pass=' . $pass . ' tables=' . $residue . ' openTransactions=' . $openTransactions . "\n";
        if ($residue !== 0 || $openTransactions !== 0) {
            fwrite(STDERR, "The suite left database objects or transactions behind.\n");

            return EXIT_TEST_FAILURE;
        }
    }

    return 0;
}

/**
 * Runs one lifecycle (fresh service, suite passes, teardown) and returns the exit status.
 *
 * @param array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool} $cell
 * @param array{cell: ?string, freshRuns: int, suitePasses: int} $options
 * @param (callable(array<string, string>): int)|null $verification Optional external verification, with the same service lifecycle and residue checks.
 */
function lifecycle(string $root, string $cellKey, array $cell, array $options, int $number, ?callable $verification = null): int
{
    $project = 'phppersist-it-' . bin2hex(random_bytes(4));
    $environment = [
        'PP_COMPOSE_PROJECT' => $project,
        'PP_DB_IMAGE' => imageReference($cell),
        'PP_DB_ROOT_PASSWORD' => bin2hex(random_bytes(12)),
        'PP_DB_DATABASE' => 'php_persistence_it',
        'PP_DB_USER' => 'pp_it',
        'PP_DB_PASSWORD' => bin2hex(random_bytes(12)),
    ];
    if ($cell['platform'] !== null) {
        $environment['DOCKER_DEFAULT_PLATFORM'] = $cell['platform'];
    }
    $compose = ['docker', 'compose', '--project-name', $project, '--file', $root . '/compose.integration.yaml'];

    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function () use ($compose, $project, $environment, $root, $verification): void {
                if ($verification !== null) {
                    pcntl_signal(SIGINT, SIG_IGN);
                    pcntl_signal(SIGTERM, SIG_IGN);

                    throw new ConsumerVerificationInterrupted();
                }
                teardown($compose, $project, $environment, $root);
                exit(130);
            });
        }
    }

    echo '--- lifecycle ' . $number . '/' . $options['freshRuns'] . ' cell=' . $cellKey . ' image=' . $environment['PP_DB_IMAGE'] . ' project=' . $project . "\n";

    $status = EXIT_INFRASTRUCTURE;
    try {
        $status = runOnService($root, $cellKey, $cell, $options, $environment, $compose, $verification);
    } catch (ConsumerVerificationInterrupted) {
        $status = 130;
    } finally {
        try {
            $torndown = teardown($compose, $project, $environment, $root);
        } catch (ConsumerVerificationInterrupted) {
            $torndown = teardown($compose, $project, $environment, $root);
            $status = 130;
        }
    }

    return $status === 0 && !$torndown ? EXIT_INFRASTRUCTURE : $status;
}
