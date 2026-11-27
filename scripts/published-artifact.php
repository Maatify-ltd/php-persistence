<?php

declare(strict_types=1);

require_once __DIR__ . '/release-artifact.php';

const PAV_REPOSITORY = 'Maatify-ltd/php-persistence';

/** @param list<string> $command */
function publishedCommand(array $command, string $directory): string
{
    [$status, $output] = execute($command, [], $directory, 180);
    if ($status !== 0) {
        throw new RuntimeException(sprintf('%s failed (%d)', $command[0], $status));
    }

    return trim($output);
}

/**
 * Returns only status and stdout for metadata parsing. Child stderr can contain
 * credentials or download URLs and is excluded from diagnostic output.
 *
 * @param list<string> $arguments
 * @return array{int, string}
 */
function publishedComposer(array $arguments, string $workspace): array
{
    [$status, $output] = execute([
        'env', '-i', 'PATH=' . (getenv('PATH') ?: '/usr/bin:/bin'), 'HOME=' . $workspace,
        'COMPOSER_HOME=' . $workspace . '/composer-home',
        'COMPOSER_CACHE_DIR=' . $workspace . '/composer-cache',
        'COMPOSER_NO_INTERACTION=1', 'GIT_CONFIG_NOSYSTEM=1',
        'composer', '--no-interaction', '--no-plugins', '--no-scripts', ...$arguments,
    ], [], $workspace, 180);

    return [$status, trim($output)];
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
    if (!is_file($path) || is_link($path) || ($contents = @file_get_contents($path)) === false) {
        throw new RuntimeException('Cannot read required file: ' . $path);
    }

    return $contents;
}

/**
 * Writes a record built from selected values without passing through process
 * diagnostics or complete metadata.
 *
 * @param array<string, mixed> $fields
 */
function publishedLog(string $record, array $fields): void
{
    echo 'PAV ' . $record . ' ' . json_encode($fields, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
}

/** Retains a value only when it matches the field's diagnostic format. */
function publishedSafeValue(mixed $value, string $pattern): ?string
{
    if ($value === null) {
        return null;
    }

    return is_string($value) && preg_match($pattern, $value) === 1 ? $value : '[redacted]';
}

/** @return array{type: ?string, url: ?string, reference: ?string}|string|null */
function publishedReferenceEvidence(mixed $entry, string $urlPattern): array|string|null
{
    if (!is_array($entry)) {
        return $entry === null ? null : '[redacted]';
    }

    return [
        'type' => publishedSafeValue($entry['type'] ?? null, '~\A(?:git|zip|tar|path|file|hg|svn|fossil)\z~'),
        'url' => publishedSafeValue($entry['url'] ?? null, $urlPattern),
        'reference' => publishedSafeValue($entry['reference'] ?? null, '~\A[0-9a-fA-F]{7,64}\z~'),
    ];
}

/**
 * Describes metadata available from the selected channel or Composer's installed
 * record. A redacted delivery URL has no bearing on the strict validation.
 *
 * @param array<array-key, mixed> $entry
 * @return array<string, mixed>
 */
function publishedPackageEvidence(array $entry): array
{
    $repository = preg_quote(PAV_REPOSITORY, '~');

    return [
        'package' => publishedSafeValue($entry['name'] ?? null, '~\A[a-z0-9_.-]{1,64}/[a-z0-9_.-]{1,64}\z~'),
        'source' => publishedReferenceEvidence($entry['source'] ?? null, '~\Ahttps://github\.com/' . $repository . '\.git\z~'),
        'dist' => publishedReferenceEvidence($entry['dist'] ?? null, '~\Ahttps://api\.github\.com/repos/' . $repository . '/zipball/[0-9a-f]{40}\z~'),
    ];
}

function publishedVersionEvidence(mixed $version): ?string
{
    return publishedSafeValue($version, '~\A(?:dev-main|v?[0-9]{1,8}(?:\.[0-9]{1,8}){1,3}(?:-(?:alpha|beta|rc)[.-]?[0-9]{1,8})?)\z~i');
}

/**
 * Records available resolution metadata even when the Composer command fails.
 *
 * @return array<array-key, mixed>|null
 */
function publishedObserveResolution(string $output, int $commandStatus): ?array
{
    try {
        $metadata = publishedJson($output);
    } catch (RuntimeException|JsonException) {
        publishedLog('RESOLVED', ['status' => $output === '' ? 'missing' : 'invalid', 'composerExitCode' => $commandStatus]);

        return null;
    }

    $versions = $metadata['versions'] ?? null;
    $versionCount = is_array($versions) ? count($versions) : null;
    $status = match (true) {
        $versionCount === null => $versions === null ? 'missing' : 'invalid',
        $versionCount === 0 => 'missing',
        $versionCount > 1 => 'ambiguous',
        !array_is_list($versions) => 'invalid',
        default => 'observed',
    };
    publishedLog('RESOLVED', [
        'status' => $status,
        'composerExitCode' => $commandStatus,
        'versionCount' => $versionCount,
        'versions' => is_array($versions) ? array_map(publishedVersionEvidence(...), array_values($versions)) : null,
        ...publishedPackageEvidence($metadata),
    ]);

    return $metadata;
}

/**
 * Returns records for the exact package name, or null for an invalid inventory.
 *
 * @param array<array-key, mixed> $installation
 * @return list<array<array-key, mixed>>|null
 */
function publishedPackageMatches(array $installation): ?array
{
    $entries = $installation['packages'] ?? null;

    return is_array($entries)
        ? array_values(array_filter($entries, static fn(mixed $entry): bool
            => is_array($entry) && ($entry['name'] ?? null) === RELEASE_PACKAGE))
        : null;
}

/**
 * Captures installed metadata before acceptance checks or workspace cleanup,
 * including partial metadata left by a failed Composer update.
 *
 * @return array<array-key, mixed>|null
 */
function publishedObserveInstallation(string $path, int $commandStatus): ?array
{
    try {
        $installation = publishedJson(publishedRead($path));
    } catch (RuntimeException|JsonException) {
        publishedLog('OBSERVED', [
            'status' => file_exists($path) || is_link($path) ? 'invalid' : 'missing',
            'composerExitCode' => $commandStatus,
        ]);

        return null;
    }

    $matches = publishedPackageMatches($installation);
    $count = $matches === null ? null : count($matches);
    publishedLog('OBSERVED', [
        'status' => match ($count) {
            null => 'invalid',
            0 => 'missing',
            1 => 'observed',
            default => 'ambiguous',
        },
        'composerExitCode' => $commandStatus,
        'matchCount' => $count,
        'packages' => $matches === null ? null : array_map(static fn(array $entry): array => [
            ...publishedPackageEvidence($entry),
            'version' => publishedVersionEvidence($entry['version'] ?? null),
            'installationSource' => publishedSafeValue($entry['installation-source'] ?? null, '~\A(?:dist|source)\z~'),
            'installPath' => publishedSafeValue($entry['install-path'] ?? null, '~\A\.\./[a-z0-9_.-]{1,64}/[a-z0-9_.-]{1,64}\z~'),
        ], $matches),
    ]);

    return $installation;
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
    $matches = publishedPackageMatches($installation);
    if ($matches === null) {
        throw new RuntimeException('Composer did not record installed packages');
    }
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
 * @param (Closure(array<string, mixed>): void)|null $record
 * @return array<string, string>
 */
function publishedContents(string $reference, string $installed, array $runtime, ?Closure $record = null): array
{
    $expectedFiles = publishedInventory($reference);
    $installedFiles = publishedInventory($installed);
    $missingOrChanged = array_diff_assoc($expectedFiles, $installedFiles);
    $extra = array_diff_key($installedFiles, $expectedFiles);
    $referencePaths = array_keys($expectedFiles);
    $missingRequired = array_values(array_diff(RELEASE_FILES, $referencePaths));
    $missingRuntime = array_values(array_diff($runtime, $referencePaths));

    if ($record !== null) {
        $changed = [];
        foreach ($missingOrChanged as $path => $expectedHash) {
            if (isset($installedFiles[$path])) {
                $changed[$path] = ['expectedSha256' => $expectedHash, 'observedSha256' => $installedFiles[$path]];
            }
        }
        $unexpected = [];
        foreach ($extra as $path => $hash) {
            $unexpected[] = ['pathSha256' => hash('sha256', $path), 'observedSha256' => $hash];
        }
        $record([
            'installedPath' => $installed,
            'expectedFileCount' => count($expectedFiles),
            'observedFileCount' => count($installedFiles),
            'missingRequiredFilesInReference' => $missingRequired,
            'missingRuntimeFilesInReference' => $missingRuntime,
            'matchingFiles' => array_intersect_assoc($installedFiles, $expectedFiles),
            'missingFiles' => array_diff_key($expectedFiles, $installedFiles),
            'changedFiles' => $changed,
            'unexpectedFiles' => $unexpected,
        ]);
    }

    if ($missingRequired !== []) {
        throw new RuntimeException('The qualified reference omits a required file: ' . $missingRequired[0]);
    }
    if ($runtime === [] || $missingRuntime !== []) {
        throw new RuntimeException('The qualified reference omits runtime source');
    }
    if ($installedFiles !== $expectedFiles) {
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
    publishedLog('RESULT', ['integrity' => 'NOT PASS', 'stage' => 'usage', 'exitCode' => EXIT_USAGE, 'cleanup' => 'not-started']);
    fail(EXIT_USAGE, 'Usage: composer release:verify-published -- --target=' . RELEASE_TARGET . ' --sha=<full qualified SHA>');
}

$sha = $matches[1];
$root = dirname(__DIR__);
$tag = 'refs/tags/v' . RELEASE_TARGET;
publishedLog('EXPECTED', [
    'package' => RELEASE_PACKAGE, 'target' => RELEASE_TARGET, 'sha' => $sha, 'tag' => $tag,
    'installationSource' => 'dist', 'installPath' => '../' . RELEASE_PACKAGE,
]);

$workspace = sys_get_temp_dir() . '/pp-published-' . bin2hex(random_bytes(12));
if (!@mkdir($workspace, 0700)) {
    publishedLog('RESULT', ['integrity' => 'NOT PASS', 'stage' => 'workspace', 'exitCode' => EXIT_INFRASTRUCTURE, 'cleanup' => 'not-started']);
    fail(EXIT_INFRASTRUCTURE, 'Cannot create published verification workspace');
}

$verificationStatus = EXIT_TEST_FAILURE;
$stage = 'tag';
$composerStatus = null;
try {
    if (publishedCommand(['git', 'rev-parse', '--verify', $tag . '^{commit}'], $root) !== $sha) {
        throw new RuntimeException('The release tag must resolve to the exact qualified SHA');
    }

    $stage = 'manifest';
    $manifest = json_encode([
        'require' => [RELEASE_PACKAGE => RELEASE_TARGET],
        'repositories' => ['packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org']],
        'config' => ['preferred-install' => 'dist', 'allow-plugins' => false],
    ], JSON_THROW_ON_ERROR);
    if (@file_put_contents($workspace . '/composer.json', $manifest) !== strlen($manifest)) {
        throw new RuntimeException('Cannot create isolated consumer manifest');
    }

    $stage = 'composer-version';
    [$composerStatus, $versionOutput] = publishedComposer(['--version'], $workspace);
    preg_match('/^Composer version ([0-9]{1,8}\.[0-9]{1,8}\.[0-9]{1,8}(?:-(?:dev|alpha[0-9]{1,8}|beta[0-9]{1,8}|RC[0-9]{1,8}))?)(?:\s|$)/m', $versionOutput, $versionMatches);
    publishedLog('COMPOSER', [
        'version' => $versionMatches[1] ?? null,
        'composerExitCode' => $composerStatus,
        'repository' => 'https://repo.packagist.org',
        'installPreference' => 'dist',
        'plugins' => false, 'scripts' => false, 'isolatedHomeAndCache' => true,
    ]);
    if ($composerStatus !== 0 || !isset($versionMatches[1])) {
        throw new RuntimeException('Composer version is unavailable');
    }

    $stage = 'resolution';
    [$composerStatus, $metadataOutput] = publishedComposer(['show', '--all', RELEASE_PACKAGE, RELEASE_TARGET, '--format=json'], $workspace);
    $metadata = publishedObserveResolution($metadataOutput, $composerStatus);
    if ($composerStatus !== 0 || $metadata === null) {
        throw new RuntimeException('Composer resolution failed');
    }
    publishedResolvedVersion($metadata, $sha);

    $stage = 'composer-update';
    [$composerStatus] = publishedComposer(['update', '--prefer-dist', '--no-progress'], $workspace);
    $installation = publishedObserveInstallation($workspace . '/vendor/composer/installed.json', $composerStatus);
    if ($composerStatus !== 0) {
        throw new RuntimeException('Composer update failed');
    }

    $stage = 'installation';
    if ($installation === null) {
        throw new RuntimeException('Composer installation metadata is unavailable');
    }
    publishedInstalledPackage($installation, $sha);

    $stage = 'reference-archive';
    $composerStatus = null;
    $reference = $workspace . '/qualified';
    $archive = $workspace . '/qualified.tar';
    if (!@mkdir($reference, 0700)) {
        throw new RuntimeException('Cannot create reference directory');
    }
    publishedCommand(['git', 'archive', '--format=tar', '--output=' . $archive, $sha], $root);
    publishedCommand(['tar', '-xf', $archive, '-C', $reference], $workspace);
    $runtime = publishedCommand(['git', 'ls-tree', '-r', '--name-only', $sha, '--', 'src'], $root);
    $stage = 'content';
    publishedContents(
        $reference,
        $workspace . '/vendor/' . RELEASE_PACKAGE,
        $runtime === '' ? [] : explode("\n", $runtime),
        static fn(array $evidence) => publishedLog('CONTENT', $evidence),
    );
    $stage = 'complete';
    $verificationStatus = 0;
} catch (Throwable) {
    // Metadata, filesystem errors and process diagnostics can contain secrets or control characters.
    fwrite(STDERR, "Published artifact verification failed at {$stage}\n");
} finally {
    [$cleanupStatus] = execute(['rm', '-rf', '--', $workspace]);
    $clean = $cleanupStatus === 0 && !file_exists($workspace);
    $status = $clean ? $verificationStatus : EXIT_INFRASTRUCTURE;
}

publishedLog('RESULT', [
    'integrity' => $status === 0 ? 'PASS' : 'NOT PASS',
    'stage' => $stage,
    'exitCode' => $status,
    'verificationExitCode' => $verificationStatus,
    'composerExitCode' => $composerStatus,
    'cleanup' => $clean ? 'verified' : 'failed',
]);
if ($status === 0) {
    echo 'PAV VERIFICATION OK target=' . RELEASE_TARGET . " sha={$sha}\n";
}
exit($status);
