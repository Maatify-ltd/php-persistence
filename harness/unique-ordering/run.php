<?php

declare(strict_types=1);

// Opt-in only. Reuse the repository's disposable Compose lifecycle and residue checks.
$root = dirname(__DIR__, 2);
require $root . '/scripts/integration-support.php';

$options = parseOptions(array_slice($argv, 1));
$matrix = loadMatrix($root);
$key = $options['cell'] ?? $matrix['default'];
if (!isset($matrix['cells'][$key]) || !is_file($root . '/vendor/bin/phpunit')) {
    fail(EXIT_USAGE, 'Choose a cell from docker/integration-matrix.json and run composer install.');
}

startBudget();
/** @param array<string, string> $environment */
function runUniqueOrderingVerification(array $environment): int
{
    $root = dirname(__DIR__, 2);
    $command = [PHP_BINARY, $root . '/vendor/bin/phpunit', '--no-progress', '--testdox', __DIR__ . '/UniqueOrderingProofTest.php'];
    return passthrough($command, $environment, $root, boxed(secondsFromEnvironment('PP_SUITE_TIMEOUT_SECONDS', 900)))
        === 0 ? 0 : EXIT_TEST_FAILURE;
}

for ($run = 1; $run <= $options['freshRuns']; $run++) {
    $status = lifecycle($root, $key, $matrix['cells'][$key], $options, $run, runUniqueOrderingVerification(...));
    if ($status !== 0) {
        exit($status);
    }
}
echo "UNIQUE ORDERING PROOF OK cell={$key}\n";
