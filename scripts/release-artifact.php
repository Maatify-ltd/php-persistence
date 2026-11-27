<?php

declare(strict_types=1);

require_once __DIR__ . '/integration-support.php';

const RELEASE_PACKAGE = 'maatify/php-persistence';
const RELEASE_TARGET = '1.0.0';
const RELEASE_FILES = [
    'composer.json',
    'LICENSE',
    'README.md',
    'CHANGELOG.md',
    'SECURITY.md',
    'PHP_PERSISTENCE_PACKAGE_REFERENCE.md',
    'CONTRIBUTING.md',
    'docs/guides/CONSUMER_VERIFICATION.md',
    'docs/guides/RELEASE_ARTIFACT_VERIFICATION.md',
    'docs/guides/USAGE_GUIDE.md',
    'examples/_bootstrap.php',
    'examples/ordering_basic_usage.php',
    'examples/ordering_unique_usage.php',
    'examples/pagination_basic_usage.php',
    'examples/pagination_dynamic_sort_usage.php',
    'examples/pagination_keyset_usage.php',
    'examples/pagination_snapshot_usage.php',
    'examples/pagination_static_sort_usage.php',
    'examples/transaction_basic_usage.php',
    'examples/verified_transaction_usage.php',
    'llms.txt',
];

/**
 * @param list<string> $arguments
 * @param array<string, string> $environment
 */
function releaseCommand(array $arguments, string $directory, array $environment = []): string
{
    [$status, $output, $errors] = execute($arguments, $environment, $directory, 90);
    if ($status !== 0) {
        throw new RuntimeException(sprintf('%s failed (%d): %s', $arguments[0], $status, trim($errors)));
    }

    return trim($output);
}

function releaseFile(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read release file: ' . basename($path));
    }

    return $contents;
}

/**
 * Checks Composer identity and production autoload, exact README installation
 * and artifact labels, the CHANGELOG allocation, and SECURITY lifecycle
 * markers. The release claims also require separate semantic review.
 */
function verifyReleasePresentation(string $directory, string $target): void
{
    $manifest = json_decode(releaseFile($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $autoload = is_array($manifest) ? ($manifest['autoload'] ?? null) : null;
    if (!is_array($manifest) || ($manifest['name'] ?? null) !== RELEASE_PACKAGE
        || ($manifest['type'] ?? null) !== 'library' || ($manifest['license'] ?? null) !== 'proprietary'
        || array_key_exists('version', $manifest) || !is_array($autoload)
        || ($autoload['psr-4'] ?? null) !== ['Maatify\\PhpPersistence\\' => 'src/']
    ) {
        throw new RuntimeException('Unexpected archived Composer identity or production autoload');
    }

    $readme = explode("\n", releaseFile($directory . '/README.md'));
    if (!in_array('This release artifact targets Stable `' . $target . '`.', $readme, true)
        || !in_array('The following command resolves after this exact version is published on Packagist.', $readme, true)
        || !in_array('composer require ' . RELEASE_PACKAGE . ':' . $target, $readme, true)
    ) {
        throw new RuntimeException('Archived README is missing the exact Stable target or its conditional install command');
    }

    $changelog = releaseFile($directory . '/CHANGELOG.md');
    $targetHeading = '## [' . $target . ']';
    $targetLabel = preg_quote('[' . $target . ']', '/');
    if (preg_match_all('/^[ \t]{0,3}##[ \t]+' . $targetLabel . '[^\r\n]*$/m', $changelog, $headings, PREG_OFFSET_CAPTURE) !== 1
        || $headings[0][0][0] !== $targetHeading
        || (preg_match('/^## \[Unreleased\]$/m', $changelog, $unreleased, PREG_OFFSET_CAPTURE) === 1
            && $unreleased[0][1] > $headings[0][0][1])
    ) {
        throw new RuntimeException('Archived CHANGELOG must allocate the release to one exact, undated target heading');
    }

    $security = explode("\n", releaseFile($directory . '/SECURITY.md'));
    if (!in_array('This security policy accompanies the Stable target `' . $target . '`.', $security, true)
        || !in_array('No Stable support line was established during release preparation.', $security, true)
    ) {
        throw new RuntimeException('Archived SECURITY is missing the Stable target or preparation support status');
    }
}

/**
 * Checks archive inventory against the allowed and required paths, then
 * compares extracted bytes with the selected commit. Rejects duplicate
 * paths, unexpected files and private key markers before checking the
 * target presentation. Returns the sorted file list for evidence.
 *
 * @param non-empty-list<string> $requiredFiles
 * @return list<string>
 */
function verifyReleaseArchive(string $archive, string $workspace, string $source, string $label, string $target, array $requiredFiles): array
{
    $files = [];
    foreach (explode("\n", releaseCommand(['tar', '-tf', $archive], $workspace)) as $entry) {
        $path = rtrim($entry, '/');
        if ($path === '' || preg_match('~^(?:src|docs|examples)(?:/|$)|^(?:' . implode('|', array_map(
            static fn(string $file): string => preg_quote($file, '~'),
            [...RELEASE_FILES, 'AGENTS.md', 'CODE_OF_CONDUCT.md'],
        )) . ')$~D', $path) !== 1
            || preg_match('~(?:^|/)(?:[.]\.?|[.]env[^/]*|[.]git[^/]*|[.]idea|[.]vscode|vendor|tests|harness|composer[.]lock|auth[.]json)(?:/|$)|[.](?:key|pem|pfx|p12)$~iD', $path) !== 0
        ) {
            throw new RuntimeException($label . ' contains an unexpected archive path: ' . $path);
        }
        if (!str_ends_with($entry, '/')) {
            $files[] = $path;
        }
    }
    if (count($files) !== count(array_unique($files)) || array_diff($requiredFiles, $files) !== []) {
        throw new RuntimeException($label . ' omits required files or contains duplicate paths');
    }

    $directory = $workspace . '/' . $label;
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create archive inspection directory');
    }
    releaseCommand(['tar', '-xf', $archive, '-C', $directory], $workspace);
    foreach ($files as $path) {
        if (is_link($directory . '/' . $path) || !is_file($source . '/' . $path)) {
            throw new RuntimeException($label . ' contains an unexpected file type or untracked file: ' . $path);
        }
        $contents = releaseFile($directory . '/' . $path);
        if ($contents !== releaseFile($source . '/' . $path)) {
            throw new RuntimeException($label . ' contents do not match the selected commit: ' . $path);
        }
        if (preg_match('/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----/', $contents) === 1) {
            throw new RuntimeException($label . ' contains private key material: ' . $path);
        }
    }
    verifyReleasePresentation($directory, $target);
    sort($files);

    return $files;
}

// The published verifier reuses the release target, file set and presentation checks.
$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (!is_string($scriptFilename) || realpath($scriptFilename) !== __FILE__) {
    return;
}

$root = dirname(__DIR__);
$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || count($arguments) !== 3 || !is_string($arguments[1]) || !is_string($arguments[2])
    || $arguments[1] !== '--target=' . RELEASE_TARGET || preg_match('/^--sha=([0-9a-f]{40})$/D', $arguments[2], $matches) !== 1
) {
    fail(EXIT_USAGE, 'Usage: composer release:verify -- --target=' . RELEASE_TARGET . ' --sha=<full commit SHA>');
}

$sha = $matches[1];
$target = RELEASE_TARGET;
$workspace = sys_get_temp_dir() . '/pp-release-' . bin2hex(random_bytes(12));
if (!mkdir($workspace, 0700)) {
    fail(EXIT_INFRASTRUCTURE, 'Cannot create release verification workspace');
}

$status = EXIT_TEST_FAILURE;
$tree = '';
try {
    if (releaseCommand(['git', 'rev-parse', $sha . '^{commit}'], $root) !== $sha) {
        throw new RuntimeException('Release SHA must name the exact selected commit');
    }
    $tree = releaseCommand(['git', 'rev-parse', $sha . '^{tree}'], $root);
    $objects = releaseCommand(['git', 'ls-tree', '-r', $sha], $root);
    if (preg_match('/^(?:120000|160000) /m', $objects) !== 0) {
        throw new RuntimeException('Release source contains symbolic links or submodules');
    }
    echo "RELEASE ARTIFACT candidate target={$target} sha={$sha} tree={$tree} package=" . RELEASE_PACKAGE . "\n";

    $source = $workspace . '/source';
    releaseCommand(['git', 'clone', '--quiet', '--shared', '--no-checkout', $root, $source], $workspace);
    releaseCommand(['git', '-c', 'advice.detachedHead=false', 'checkout', '--detach', $sha], $source);
    $runtime = releaseCommand(['git', 'ls-tree', '-r', '--name-only', $sha, '--', 'src'], $source);
    if ($runtime === '') {
        throw new RuntimeException('Release source has no runtime files');
    }
    $requiredFiles = [...RELEASE_FILES, ...explode("\n", $runtime)];

    releaseCommand(['git', 'archive', '--format=tar', '--output=' . $workspace . '/git.tar', $sha], $source);
    releaseCommand(['composer', 'archive', '--no-interaction', '--no-plugins', '--no-scripts', '--format=tar', '--dir=' . $workspace, '--file=composer'], $source, [
        'COMPOSER' => $source . '/composer.json',
        'COMPOSER_HOME' => $workspace . '/composer-home',
    ]);
    foreach (['git', 'composer'] as $label) {
        $archive = $workspace . '/' . $label . '.tar';
        $files = verifyReleaseArchive($archive, $workspace, $source, $label, $target, $requiredFiles);
        echo 'RELEASE INVENTORY ' . json_encode(['archive' => $label, 'sha256' => hash_file('sha256', $archive), 'files' => $files], JSON_THROW_ON_ERROR) . "\n";
    }
    $status = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release artifact verification failed: ' . $exception->getMessage() . "\n");
} finally {
    [$cleanup] = execute(['rm', '-rf', '--', $workspace]);
    if ($cleanup !== 0 || file_exists($workspace)) {
        fwrite(STDERR, "Release verification workspace cleanup failed\n");
        $status = EXIT_INFRASTRUCTURE;
    }
}

if ($status === 0) {
    echo "RELEASE ARTIFACT VERIFICATION OK target={$target} sha={$sha} tree={$tree} package=" . RELEASE_PACKAGE . "\n";
}
exit($status);
