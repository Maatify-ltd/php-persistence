<?php

declare(strict_types=1);

require_once __DIR__ . '/integration-support.php';

const DEPENDENCY_AUDIT_COMPOSER = ['composer', '--no-interaction', '--no-plugins', '--no-scripts', '--no-ansi'];

/** Checks variable values that Composer reads outside the resolved config object. */
function checkDependencyAuditEnvironment(): void
{
    $allowed = [
        'COMPOSER' => ['composer.json'],
        'COMPOSER_VENDOR_DIR' => ['vendor'],
        'COMPOSER_POLICY' => ['1', 'true', 'on'],
        'COMPOSER_NO_BLOCKING' => ['0'],
        'COMPOSER_NO_SECURITY_BLOCKING' => ['0'],
        'COMPOSER_NO_AUDIT' => ['0'],
        'COMPOSER_NO_DEV' => ['0'],
        'COMPOSER_DISABLE_NETWORK' => ['0'],
        'COMPOSER_AUDIT_ABANDONED' => ['fail'],
        'COMPOSER_POLICY_ADVISORIES_BLOCK' => ['1', 'true', 'on'],
        'COMPOSER_POLICY_MALWARE_BLOCK' => ['1', 'true', 'on'],
    ];

    foreach ($allowed as $variable => $values) {
        $value = getenv($variable);
        if ($value !== false && !in_array($value, $values, true)) {
            throw new RuntimeException($variable . ' is incompatible with the required dependency audit');
        }
    }
}

/** Reads the effective value reported by Composer, without printing its configuration. */
function dependencyAuditConfig(string $key, string $directory): mixed
{
    [$status, $output] = execute([...DEPENDENCY_AUDIT_COMPOSER, 'config', $key], [], $directory);
    if ($status !== 0) {
        throw new RuntimeException('Cannot read Composer config ' . $key . ' (exit ' . $status . ')');
    }

    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

/** Validates the policy and advisory source after Composer merges project and global config. */
function checkDependencyAuditConfig(string $directory): void
{
    $policy = dependencyAuditConfig('policy', $directory);
    $required = [
        'advisories' => ['block' => true, 'audit' => 'fail'],
        'malware' => ['block' => true, 'block-scope' => 'all', 'audit' => 'fail'],
        'abandoned' => ['audit' => 'fail'],
    ];
    $allowedExtras = [
        'advisories' => ['ignore' => [], 'ignore-id' => [], 'ignore-severity' => []],
        'malware' => ['ignore' => [], 'ignore-source' => []],
        'abandoned' => ['ignore' => []],
    ];
    if (!is_array($policy) || array_diff_key($policy, $required + ['ignore-unreachable' => null]) !== []) {
        throw new RuntimeException('Unexpected Composer dependency policy configuration');
    }

    foreach ($required as $name => $settings) {
        $actual = $policy[$name] ?? null;
        if ($name === 'abandoned' && is_array($actual) && is_bool($actual['block'] ?? null)) {
            unset($actual['block']);
        }
        if (!is_array($actual) || array_diff_key($actual, $settings + $allowedExtras[$name]) !== []) {
            throw new RuntimeException('Unexpected Composer dependency policy settings for ' . $name);
        }
        foreach ($settings as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                throw new RuntimeException('Required Composer dependency policy setting: ' . $name . '.' . $key);
            }
        }
        foreach ($allowedExtras[$name] as $key => $value) {
            if (($actual[$key] ?? []) !== $value) {
                throw new RuntimeException('Dependency policy exceptions are prohibited for ' . $name . '.' . $key);
            }
        }
    }

    $ignoreUnreachable = $policy['ignore-unreachable'] ?? null;
    if ($ignoreUnreachable === null) {
        $legacyAudit = dependencyAuditConfig('audit', $directory);
        if (!is_array($legacyAudit)) {
            throw new RuntimeException('Unexpected Composer audit configuration');
        }
        $ignoreUnreachable = $legacyAudit['ignore-unreachable'] ?? false;
    }
    $isResolutionScope = static fn(mixed $scope): bool => in_array($scope, ['install', 'update'], true);
    if ($ignoreUnreachable !== false && (!is_array($ignoreUnreachable)
        || !array_is_list($ignoreUnreachable)
        || array_filter($ignoreUnreachable, $isResolutionScope) !== $ignoreUnreachable)
    ) {
        throw new RuntimeException('Composer may not ignore an unreachable source during audit');
    }

    $repositories = dependencyAuditConfig('repositories', $directory);
    $packagist = is_array($repositories) && count($repositories) === 1 ? ($repositories['packagist.org'] ?? null) : null;
    if (!is_array($packagist) || count($packagist) !== 2
        || ($packagist['type'] ?? null) !== 'composer'
        || ($packagist['url'] ?? null) !== 'https://repo.packagist.org'
    ) {
        throw new RuntimeException('Expected Composer to use the default Packagist advisory source');
    }
    if (dependencyAuditConfig('disable-tls', $directory) !== false
        || dependencyAuditConfig('secure-http', $directory) !== true
    ) {
        throw new RuntimeException('Composer audit requires secure HTTPS transport');
    }
}

if (($argc ?? 0) !== 1) {
    fwrite(STDERR, "Usage: php scripts/dependency-audit.php\n");
    exit(2);
}

$directory = dirname(__DIR__);
try {
    checkDependencyAuditEnvironment();
    [$status, $version] = execute([...DEPENDENCY_AUDIT_COMPOSER, '--version'], [], $directory);
    if ($status !== 0
        || preg_match('/\AComposer version (\d+\.\d+\.\d+)(?:\s|\z)/', $version, $matches) !== 1
        || version_compare($matches[1], '2.10.0', '<')
    ) {
        throw new RuntimeException('Composer 2.10.0 or later is required');
    }
    checkDependencyAuditConfig($directory);
    $lock = $directory . '/composer.lock';
    if (!is_file($lock) || is_link($lock)) {
        throw new RuntimeException('Resolve dependencies with composer update before running the dependency audit');
    }
} catch (JsonException|RuntimeException $error) {
    fwrite(STDERR, 'Dependency audit: ' . $error->getMessage() . "\n");
    exit(1);
}

$status = passthrough(
    [...DEPENDENCY_AUDIT_COMPOSER, 'validate', '--strict', '--no-check-publish', '--check-lock'],
    [],
    $directory,
    120,
);
if ($status !== 0) {
    exit($status);
}

exit(passthrough(
    [...DEPENDENCY_AUDIT_COMPOSER, 'audit', '--locked', '--abandoned=fail'],
    [],
    $directory,
    120,
));
