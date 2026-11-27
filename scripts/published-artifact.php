<?php

declare(strict_types=1);

require_once __DIR__ . '/release-artifact.php';

const PAV_REPOSITORY = 'Maatify-ltd/php-persistence';

/** @param list<string> $command */
function publishedCommand(array $command, string $directory): string
{
    [$status, $output, $errors] = execute($command, [], $directory, 180);
    if ($status !== 0) {
        throw new RuntimeException(sprintf('%s failed (%d): %s', $command[0], $status, trim($errors)));
    }

    return trim($output);
}

/** @param list<string> $arguments */
function publishedComposer(array $arguments, string $workspace): string
{
    return publishedCommand([
        'env', '-i', 'PATH=' . (getenv('PATH') ?: '/usr/bin:/bin'), 'HOME=' . $workspace,
        'COMPOSER_HOME=' . $workspace . '/composer-home',
        'COMPOSER_CACHE_DIR=' . $workspace . '/composer-cache',
        'COMPOSER_NO_INTERACTION=1', 'GIT_CONFIG_NOSYSTEM=1',
        'composer', '--no-interaction', '--no-plugins', '--no-scripts', ...$arguments,
    ], $workspace);
}

/** @return array<array-key, mixed> */
function publishedJson(string $json): array
{
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) {
        throw new RuntimeException('Expected a JSON object in Composer metadata');
    }

    return $data;
}

function publishedRead(string $path): string
{
    if (!is_file($path) || is_link($path) || ($contents = file_get_contents($path)) === false) {
        throw new RuntimeException('Cannot read required file: ' . $path);
    }

    return $contents;
}

/** Accepts the exact Stable version with or without Composer's tag prefix. */
function publishedVersion(mixed $version): bool
{
    return $version === RELEASE_TARGET || $version === 'v' . RELEASE_TARGET;
}

/**
 * Returns sorted relative paths and SHA-256 hashes for regular files.
 * The caller compares installed dist with the qualified archive and
 * rejects path or byte differences. Unsupported entry types fail.
 *
 * @return array<string, string>
 */
function publishedInventory(string $directory): array
{
    if (!is_dir($directory) || is_link($directory)) {
        throw new RuntimeException('Expected an installed or reference directory');
    }
    $files = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($entries as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Cannot read artifact directory entry');
        }
        $path = $entry->getPathname();
        $relative = substr($path, strlen($directory) + 1);
        if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
            throw new RuntimeException('Unexpected artifact entry: ' . $relative);
        }
        if ($entry->isFile()) {
            $files[$relative] = hash('sha256', publishedRead($path));
        }
    }
    ksort($files);

    return $files;
}

/**
 * Requires both GitHub source and zip dist references to name the exact
 * qualified SHA and expected repository locations. The caller separately
 * requires a dist installation before comparing the installed inventory.
 *
 * @param array<array-key, mixed> $metadata
 */
function publishedReference(array $metadata, string $sha): void
{
    $source = $metadata['source'] ?? null;
    $dist = $metadata['dist'] ?? null;
    if (!is_array($dist) || ($dist['type'] ?? null) !== 'zip'
        || ($dist['url'] ?? null) !== 'https://api.github.com/repos/' . PAV_REPOSITORY . '/zipball/' . $sha
        || ($dist['reference'] ?? null) !== $sha
        || !is_array($source) || ($source['type'] ?? null) !== 'git'
        || ($source['url'] ?? null) !== 'https://github.com/' . PAV_REPOSITORY . '.git'
        || ($source['reference'] ?? null) !== $sha
    ) {
        throw new RuntimeException('Packagist must expose GitHub dist and source for the exact qualified SHA');
    }
}

/**
 * Verifies the published package's exact version and references before installation.
 *
 * @param array<array-key, mixed> $metadata
 */
function publishedResolvedVersion(array $metadata, string $sha): string
{
    $versions = $metadata['versions'] ?? null;
    $version = is_array($versions) && count($versions) === 1 ? $versions[0] ?? null : null;
    if (($metadata['name'] ?? null) !== RELEASE_PACKAGE || !is_string($version) || !publishedVersion($version)) {
        throw new RuntimeException('Packagist did not resolve the exact package and release version');
    }
    publishedReference($metadata, $sha);

    return $version;
}

/**
 * Requires the exact installed package at the standard path and reads the
 * observed mode from Composer metadata. Source fallback fails even with matching references.
 *
 * @param array<array-key, mixed> $installation
 * @return array<array-key, mixed>
 */
function publishedInstalledPackage(array $installation, string $sha): array
{
    $packages = $installation['packages'] ?? null;
    if (!is_array($packages)) {
        throw new RuntimeException('Composer did not record installed packages');
    }
    $matches = array_values(array_filter($packages, static fn(mixed $package): bool
        => is_array($package) && ($package['name'] ?? null) === RELEASE_PACKAGE));
    $package = $matches[0] ?? null;
    if (count($matches) !== 1 || !is_array($package) || !publishedVersion($package['version'] ?? null)
        || ($package['installation-source'] ?? null) !== 'dist'
        || ($package['install-path'] ?? null) !== '../' . RELEASE_PACKAGE
    ) {
        throw new RuntimeException('Composer did not install the exact release from dist at the expected path');
    }
    publishedReference($package, $sha);

    return $package;
}

/**
 * Checks the qualified archive's required files and full runtime inventory,
 * compares the installed bytes, and applies the shared release presentation checks.
 *
 * @param list<string> $runtime
 * @return array<string, string>
 */
function publishedContents(string $reference, string $installed, array $runtime): array
{
    $expectedFiles = publishedInventory($reference);
    $installedFiles = publishedInventory($installed);
    foreach (RELEASE_FILES as $required) {
        if (!isset($expectedFiles[$required])) {
            throw new RuntimeException('The qualified reference omits a required file: ' . $required);
        }
    }
    if ($runtime === [] || array_diff($runtime, array_keys($expectedFiles)) !== []) {
        throw new RuntimeException('The qualified reference omits runtime source');
    }
    if ($installedFiles !== $expectedFiles) {
        $missingOrChanged = array_diff_assoc($expectedFiles, $installedFiles);
        $extra = array_diff_key($installedFiles, $expectedFiles);
        throw new RuntimeException('Installed dist differs from the qualified archive: '
            . implode(', ', [...array_keys($missingOrChanged), ...array_keys($extra)]));
    }

    verifyReleasePresentation($installed, RELEASE_TARGET);

    return $installedFiles;
}

// Regression tests load the same metadata checks without starting publication verification.
$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (!is_string($scriptFilename) || realpath($scriptFilename) !== __FILE__) {
    return;
}

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments) || count($arguments) !== 3 || ($arguments[1] ?? null) !== '--target=' . RELEASE_TARGET
    || !is_string($arguments[2] ?? null) || preg_match('/^--sha=([0-9a-f]{40})$/D', $arguments[2], $matches) !== 1
) {
    fail(EXIT_USAGE, 'Usage: composer release:verify-published -- --target=' . RELEASE_TARGET . ' --sha=<full qualified SHA>');
}

$sha = $matches[1];
$root = dirname(__DIR__);
$workspace = sys_get_temp_dir() . '/pp-published-' . bin2hex(random_bytes(12));
if (!mkdir($workspace, 0700)) {
    fail(EXIT_INFRASTRUCTURE, 'Cannot create published verification workspace');
}

$status = EXIT_TEST_FAILURE;
try {
    $tag = 'refs/tags/v' . RELEASE_TARGET;
    if (publishedCommand(['git', 'rev-parse', '--verify', $tag . '^{commit}'], $root) !== $sha) {
        throw new RuntimeException('The release tag must resolve to the exact qualified SHA');
    }
    $manifest = json_encode([
        'require' => [RELEASE_PACKAGE => RELEASE_TARGET],
        'repositories' => ['packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org']],
        'config' => ['preferred-install' => 'dist', 'allow-plugins' => false],
    ], JSON_THROW_ON_ERROR);
    if (file_put_contents($workspace . '/composer.json', $manifest) !== strlen($manifest)) {
        throw new RuntimeException('Cannot create isolated consumer manifest');
    }

    echo publishedComposer(['--version'], $workspace) . "\n";
    echo "PAV source=https://repo.packagist.org preference=dist plugins=off scripts=off isolated-home-and-cache=true\n";
    $metadata = publishedJson(publishedComposer(['show', '--all', RELEASE_PACKAGE, RELEASE_TARGET, '--format=json'], $workspace));
    $resolvedVersion = publishedResolvedVersion($metadata, $sha);

    publishedComposer(['update', '--prefer-dist', '--no-progress'], $workspace);
    $installation = publishedJson(publishedRead($workspace . '/vendor/composer/installed.json'));
    $package = publishedInstalledPackage($installation, $sha);

    $reference = $workspace . '/qualified';
    $archive = $workspace . '/qualified.tar';
    if (!mkdir($reference, 0700)) {
        throw new RuntimeException('Cannot create reference directory');
    }
    publishedCommand(['git', 'archive', '--format=tar', '--output=' . $archive, $sha], $root);
    publishedCommand(['tar', '-xf', $archive, '-C', $reference], $workspace);
    $installed = $workspace . '/vendor/' . RELEASE_PACKAGE;
    $runtime = publishedCommand(['git', 'ls-tree', '-r', '--name-only', $sha, '--', 'src'], $root);
    $files = publishedContents($reference, $installed, $runtime === '' ? [] : explode("\n", $runtime));
    echo 'PAV EVIDENCE ' . json_encode([
        'package' => RELEASE_PACKAGE, 'target' => RELEASE_TARGET, 'sha' => $sha, 'tag' => $tag,
        'resolvedVersion' => $resolvedVersion, 'installedVersion' => $package['version'],
        'installationSource' => $package['installation-source'], 'installedPath' => $installed,
        'dist' => $package['dist'], 'source' => $package['source'], 'files' => $files,
    ], JSON_THROW_ON_ERROR) . "\n";
    $status = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Published artifact verification failed: ' . $exception->getMessage() . "\n");
} finally {
    [$cleanup] = execute(['rm', '-rf', '--', $workspace]);
    if ($cleanup !== 0 || file_exists($workspace)) {
        fwrite(STDERR, "Published verification workspace cleanup failed\n");
        $status = EXIT_INFRASTRUCTURE;
    }
}

if ($status === 0) {
    echo 'PAV VERIFICATION OK target=' . RELEASE_TARGET . " sha={$sha}\n";
}
exit($status);
