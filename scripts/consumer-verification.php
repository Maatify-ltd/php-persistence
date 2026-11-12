<?php

declare(strict_types=1);

/**
 * Runs the Composer consumer and coexistence verification on two fresh MySQL services.
 */
require __DIR__ . '/integration-support.php';

const CONSUMER_PACKAGE = 'maatify/php-persistence';
const PUBLISHED_RC = '1.0.0-rc.1';
const PUBLISHED_RC_SHA = '34c3b874525d36ae064c162cdd553bc75d18772b';
const LEGACY_PACKAGE = 'maatify/persistence';
const LEGACY_RELEASE = '1.4.0';
const LEGACY_RELEASE_REF = 'fc1459b49fe5af5ead17814eeb3ada7a3fa81791';
const LEGACY_SOURCE_REF = '1f6a7aafe601090b7e55f60d83794364afdefe67';
const CONSUMER_CELL = 'mysql-8.4.13';

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 */
function consumerCommand(array $command, string $directory, array $environment = []): string
{
    if (remainingWorkSeconds() < 1) {
        throw new RuntimeException('Consumer verification time budget exhausted');
    }

    [$status, $out, $error] = execute($command, $environment, $directory, boxed(180));
    if ($status !== 0) {
        throw new RuntimeException($command[0] . ' failed (' . $status . '): ' . $error);
    }

    return trim($out);
}

/** @param array<string, mixed> $config */
function writeConsumerManifest(string $directory, array $config): void
{
    if (!mkdir($directory, 0700) || file_put_contents($directory . '/composer.json', json_encode($config, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot create clean consumer project');
    }
}

/**
 * Creates one independent Composer root and verifies a production installation before running its scenario.
 *
 * @param array<string, string> $environment
 * @param array<string, mixed> $policy
 */
function runConsumerRoot(string $workspace, string $scenario, string $candidateRef, string $candidateTree, array $environment, array $policy): int
{
    $directory = $workspace . '/' . $scenario;
    $candidateVersion = PUBLISHED_RC;
    $legacyVersion = $scenario === 'coexistence' ? LEGACY_RELEASE : 'dev-baseline';
    $legacyRef = $scenario === 'coexistence' ? LEGACY_RELEASE_REF : LEGACY_SOURCE_REF;
    $repositories = [];
    if ($scenario === 'differential') {
        $repositories['legacy-baseline'] = ['type' => 'path', 'url' => $workspace . '/persistence-' . LEGACY_SOURCE_REF, 'options' => [
            'symlink' => false, 'reference' => 'none', 'versions' => [LEGACY_PACKAGE => $legacyVersion],
        ]];
    }

    $repositories['packagist.org'] = ['type' => 'composer', 'url' => 'https://repo.packagist.org'];

    writeConsumerManifest($directory, [
        'name' => 'verification/consumer',
        'repositories' => $repositories,
        'require' => [CONSUMER_PACKAGE => $candidateVersion, LEGACY_PACKAGE => $legacyVersion],
        'config' => ['policy' => $policy, 'allow-plugins' => false],
    ]);
    $composer = [
        'env', '-i', 'PATH=' . (getenv('PATH') ?: '/usr/bin:/bin'), 'HOME=' . $directory,
        'COMPOSER_HOME=' . $directory . '/composer-home',
        'COMPOSER_CACHE_DIR=' . $directory . '/composer-cache',
        'COMPOSER_NO_INTERACTION=1', 'GIT_CONFIG_NOSYSTEM=1',
        'composer', '--no-plugins', '--no-scripts',
    ];
    consumerCommand([...$composer, 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress'], $directory);
    consumerCommand([...$composer, 'check-platform-reqs', '--no-dev'], $directory);
    $audit = consumerCommand([...$composer, 'audit', '--no-dev', '--no-interaction', '--abandoned=fail'], $directory);
    echo $audit . "\n";

    $installed = json_decode((string) file_get_contents($directory . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($installed) || !is_array($installed['packages'] ?? null)) {
        throw new RuntimeException('Invalid production lock metadata');
    }

    $references = [];
    foreach ($installed['packages'] as $package) {
        if (is_array($package) && is_string($package['name'] ?? null)) {
            $dist = $package['dist'] ?? null;
            $references[$package['name']] = is_array($dist) ? ($dist['reference'] ?? null) : null;
        }
    }
    if (!array_key_exists(CONSUMER_PACKAGE, $references)
        || !array_key_exists(LEGACY_PACKAGE, $references)
        || ($scenario === 'coexistence' && $references[LEGACY_PACKAGE] !== $legacyRef)
    ) {
        throw new RuntimeException('Installed lock does not identify the intended dependencies');
    }

    $metadata = json_decode((string) file_get_contents($directory . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $packages = is_array($metadata) ? ($metadata['packages'] ?? null) : null;
    $candidate = null;
    if (is_array($packages)) {
        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === CONSUMER_PACKAGE) {
                $candidate = $package;
                break;
            }
        }
    }
    $dist = $candidate['dist'] ?? null;
    if (($candidate['version'] ?? null) !== 'v' . PUBLISHED_RC
        || ($candidate['installation-source'] ?? null) !== 'dist'
        || !is_array($dist)
        || ($dist['reference'] ?? null) !== $candidateRef
        || ($dist['url'] ?? null) !== 'https://api.github.com/repos/Maatify-ltd/php-persistence/zipball/' . $candidateRef
        || $references[CONSUMER_PACKAGE] !== $candidateRef
    ) {
        throw new RuntimeException('Installed candidate is not the exact published Packagist dist');
    }
    echo 'PACKAGIST-INSTALL scenario=' . $scenario . ' version=v' . PUBLISHED_RC . ' mode=dist reference=' . $candidateRef . "\n";

    $candidateClass = '/src/Transaction/Repository/PdoTransactionRunner.php';
    $installedPath = $directory . '/vendor/' . CONSUMER_PACKAGE;
    $sourceDigest = hash_file('sha256', $workspace . '/candidate' . $candidateClass);
    if (is_link($installedPath) || $sourceDigest === false || $sourceDigest !== hash_file('sha256', $installedPath . $candidateClass)) {
        throw new RuntimeException('Candidate installation is not an independent copy of the exact artifact');
    }

    consumerCommand(['tar', '-xf', $workspace . '/harness.tar', '-C', $directory], $workspace);
    if (remainingWorkSeconds() < 1) {
        throw new RuntimeException('Consumer verification time budget exhausted before running ' . $scenario);
    }
    echo "CONSUMER-INSTALL scenario={$scenario} candidate={$candidateRef} tree={$candidateTree} legacy={$legacyRef}\n";

    return passthrough([PHP_BINARY, $directory . '/consumer.php', $scenario], $environment, $directory, boxed(120));
}

function cleanupConsumerWorkspace(string $workspace): bool
{
    [$cleanupStatus] = execute(['rm', '-rf', '--', $workspace]);
    if ($cleanupStatus !== 0 || file_exists($workspace)) {
        fwrite(STDERR, "Consumer workspace cleanup failed\n");

        return false;
    }

    return true;
}

/** @param array<string, string> $environment */
function verifyConsumer(string $root, string $candidateRef, string $candidateTree, array $environment, int $suiteTimeout): int
{
    $overallDeadline = OrchestrationBudget::$deadline;
    $deadline = suiteDeadline($overallDeadline, microtime(true), $suiteTimeout, reserveSeconds());
    if ($deadline === null) {
        fwrite(STDERR, "Consumer verification time budget exhausted before suite startup\n");

        return EXIT_INFRASTRUCTURE;
    }

    $workspace = sys_get_temp_dir() . '/pp-consumer-' . bin2hex(random_bytes(12));
    if (!mkdir($workspace, 0700)) {
        throw new RuntimeException('Cannot create consumer workspace');
    }

    $status = EXIT_INFRASTRUCTURE;
    try {
        OrchestrationBudget::$deadline = $deadline;
        $archive = $workspace . '/candidate.tar';
        consumerCommand(['git', 'archive', '--format=tar', '--output=' . $archive, $candidateRef], $root);
        if (!mkdir($workspace . '/candidate', 0700)) {
            throw new RuntimeException('Cannot create candidate artifact directory');
        }
        consumerCommand(['tar', '-xf', $archive, '-C', $workspace . '/candidate'], $root);
        consumerCommand(['git', 'archive', '--format=tar', '--output=' . $workspace . '/harness.tar', $candidateRef . ':harness'], $root);
        $manifest = json_decode((string) file_get_contents($workspace . '/candidate/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $config = is_array($manifest) ? ($manifest['config'] ?? null) : null;
        if (!is_array($config) || !is_array($config['policy'] ?? null)) {
            throw new RuntimeException('Missing Composer policy in the candidate archive');
        }
        /** @var array<string, mixed> $policy */
        $policy = $config['policy'];
        $legacyArchive = $workspace . '/legacy.tar.gz';
        consumerCommand(['curl', '--fail', '--silent', '--show-error', '--location', '--output', $legacyArchive,
            'https://codeload.github.com/Maatify/persistence/tar.gz/' . LEGACY_SOURCE_REF], $workspace);
        consumerCommand(['tar', '-xzf', $legacyArchive], $workspace);

        $status = EXIT_TEST_FAILURE;
        if (runConsumerRoot($workspace, 'coexistence', $candidateRef, $candidateTree, $environment, $policy) === 0
            && runConsumerRoot($workspace, 'differential', $candidateRef, $candidateTree, $environment, $policy) === 0
        ) {
            $status = 0;
        }
    } catch (RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
    } finally {
        OrchestrationBudget::$deadline = $overallDeadline;
        try {
            if (!cleanupConsumerWorkspace($workspace)) {
                $status = EXIT_INFRASTRUCTURE;
            }
        } catch (ConsumerVerificationInterrupted $interruption) {
            cleanupConsumerWorkspace($workspace);

            throw $interruption;
        }
    }

    return $status;
}

$root = dirname(__DIR__);
$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || count($arguments) !== 1) {
    fail(EXIT_USAGE, 'Usage: composer test:consumer');
}
foreach (['PP_UP_TIMEOUT_SECONDS', 'PP_TEARDOWN_TIMEOUT_SECONDS'] as $setting) {
    secondsFromEnvironment($setting, 1);
}
$suiteTimeout = secondsFromEnvironment('PP_SUITE_TIMEOUT_SECONDS', 900);
startBudget();

foreach ([['docker', 'compose', 'version'], ['docker', 'info'], ['composer', '--version'], ['git', '--version'], ['curl', '--version'], ['tar', '--version']] as $check) {
    [$status] = execute($check);
    if ($status !== 0) {
        fail(EXIT_INFRASTRUCTURE, 'Prerequisite failed: ' . implode(' ', $check));
    }
}
if (!extension_loaded('pdo_mysql')) {
    fail(EXIT_INFRASTRUCTURE, 'Prerequisite failed: ext-pdo_mysql is not loaded');
}

try {
    $candidateRef = consumerCommand(['git', 'rev-parse', 'refs/tags/v' . PUBLISHED_RC . '^{commit}'], $root);
    if ($candidateRef !== PUBLISHED_RC_SHA) {
        throw new RuntimeException('Published tag does not match the qualified SHA');
    }
    $candidateTree = consumerCommand(['git', 'rev-parse', $candidateRef . '^{tree}'], $root);
} catch (RuntimeException $exception) {
    fail(EXIT_INFRASTRUCTURE, $exception->getMessage());
}
$cell = loadMatrix($root)['cells'][CONSUMER_CELL];
$options = ['cell' => CONSUMER_CELL, 'freshRuns' => 2, 'suitePasses' => 1];
for ($run = 1; $run <= $options['freshRuns']; $run++) {
    if (remainingWorkSeconds() < 1) {
        fail(EXIT_INFRASTRUCTURE, 'No time remains for another clean consumer lifecycle');
    }

    $status = lifecycle($root, CONSUMER_CELL, $cell, $options, $run, static fn(array $environment): int => verifyConsumer($root, $candidateRef, $candidateTree, $environment, $suiteTimeout));
    if ($status !== 0) {
        exit($status);
    }
}
echo "CONSUMER VERIFICATION OK (two clean lifecycles)\n";
