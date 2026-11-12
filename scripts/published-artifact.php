<?php

declare(strict_types=1);

require __DIR__ . '/integration-support.php';

const PAV_PACKAGE = 'maatify/php-persistence';
const PAV_TARGET = '1.0.0-rc.1';
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

function publishedVersion(mixed $version): bool
{
    // Composer can normalize the separator before the RC number in registry metadata.
    return is_string($version) && preg_match('/^v?1\.0\.0-rc\.?1$/iD', $version) === 1;
}

/** @return array<string, string> */
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

/** @param array<array-key, mixed> $metadata */
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

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments) || count($arguments) !== 3 || ($arguments[1] ?? null) !== '--target=' . PAV_TARGET
    || !is_string($arguments[2] ?? null) || preg_match('/^--sha=([0-9a-f]{40})$/D', $arguments[2], $matches) !== 1
) {
    fail(EXIT_USAGE, 'Usage: composer release:verify-published -- --target=' . PAV_TARGET . ' --sha=<full qualified SHA>');
}

$sha = $matches[1];
$root = dirname(__DIR__);
$workspace = sys_get_temp_dir() . '/pp-published-' . bin2hex(random_bytes(12));
if (!mkdir($workspace, 0700)) {
    fail(EXIT_INFRASTRUCTURE, 'Cannot create published verification workspace');
}

$status = EXIT_TEST_FAILURE;
try {
    $tag = 'refs/tags/v' . PAV_TARGET;
    if (publishedCommand(['git', 'rev-parse', '--verify', $tag . '^{commit}'], $root) !== $sha) {
        throw new RuntimeException('The release tag must resolve to the exact qualified SHA');
    }
    $manifest = json_encode([
        'require' => [PAV_PACKAGE => PAV_TARGET],
        'repositories' => ['packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org']],
        'config' => ['preferred-install' => 'dist', 'allow-plugins' => false],
    ], JSON_THROW_ON_ERROR);
    if (file_put_contents($workspace . '/composer.json', $manifest) !== strlen($manifest)) {
        throw new RuntimeException('Cannot create isolated consumer manifest');
    }

    echo publishedComposer(['--version'], $workspace) . "\n";
    echo "PAV source=https://repo.packagist.org preference=dist plugins=off scripts=off isolated-home-and-cache=true\n";
    $metadata = publishedJson(publishedComposer(['show', '--all', PAV_PACKAGE, PAV_TARGET, '--format=json'], $workspace));
    $versions = $metadata['versions'] ?? null;
    if (($metadata['name'] ?? null) !== PAV_PACKAGE || !is_array($versions) || count($versions) !== 1
        || !publishedVersion($versions[0] ?? null)
    ) {
        throw new RuntimeException('Packagist did not resolve the exact package and release version');
    }
    publishedReference($metadata, $sha);

    publishedComposer(['update', '--prefer-dist', '--no-progress'], $workspace);
    $installation = publishedJson(publishedRead($workspace . '/vendor/composer/installed.json'));
    $packages = $installation['packages'] ?? null;
    if (!is_array($packages)) {
        throw new RuntimeException('Composer did not record installed packages');
    }
    $matches = array_values(array_filter($packages, static fn(mixed $package): bool
        => is_array($package) && ($package['name'] ?? null) === PAV_PACKAGE));
    $package = $matches[0] ?? null;
    if (count($matches) !== 1 || !is_array($package) || !publishedVersion($package['version'] ?? null)
        || ($package['installation-source'] ?? null) !== 'dist'
        || ($package['install-path'] ?? null) !== '../' . PAV_PACKAGE
    ) {
        throw new RuntimeException('Composer did not install the exact release from dist at the expected path');
    }
    publishedReference($package, $sha);

    $reference = $workspace . '/qualified';
    $archive = $workspace . '/qualified.tar';
    if (!mkdir($reference, 0700)) {
        throw new RuntimeException('Cannot create reference directory');
    }
    publishedCommand(['git', 'archive', '--format=tar', '--output=' . $archive, $sha], $root);
    publishedCommand(['tar', '-xf', $archive, '-C', $reference], $workspace);
    $installed = $workspace . '/vendor/' . PAV_PACKAGE;
    $expectedFiles = publishedInventory($reference);
    $installedFiles = publishedInventory($installed);
    foreach (['composer.json', 'README.md', 'CHANGELOG.md', 'SECURITY.md', 'LICENSE',
        'PHP_PERSISTENCE_PACKAGE_REFERENCE.md', 'docs/guides/USAGE_GUIDE.md', 'llms.txt',
        'examples/_bootstrap.php', 'examples/transaction_basic_usage.php', 'examples/pagination_basic_usage.php',
        'examples/ordering_basic_usage.php',
    ] as $required) {
        if (!isset($expectedFiles[$required])) {
            throw new RuntimeException('The qualified reference omits a required file: ' . $required);
        }
    }
    $runtime = publishedCommand(['git', 'ls-tree', '-r', '--name-only', $sha, '--', 'src'], $root);
    if ($runtime === '' || array_diff(explode("\n", $runtime), array_keys($expectedFiles)) !== []) {
        throw new RuntimeException('The qualified reference omits runtime source');
    }
    if ($installedFiles !== $expectedFiles) {
        $missingOrChanged = array_diff_assoc($expectedFiles, $installedFiles);
        $extra = array_diff_key($installedFiles, $expectedFiles);
        throw new RuntimeException('Installed dist differs from the qualified archive: '
            . implode(', ', [...array_keys($missingOrChanged), ...array_keys($extra)]));
    }
    echo 'PAV EVIDENCE ' . json_encode([
        'package' => PAV_PACKAGE, 'target' => PAV_TARGET, 'sha' => $sha, 'tag' => $tag,
        'installedVersion' => $package['version'], 'installationSource' => 'dist', 'installedPath' => $installed,
        'dist' => $package['dist'], 'source' => $package['source'], 'files' => $installedFiles,
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

exit($status);
