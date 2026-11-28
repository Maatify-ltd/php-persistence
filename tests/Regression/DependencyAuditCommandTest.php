<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/integration-support.php';

/** Protects the shared CLI's fail-closed preflight and the Composer process boundary. */
final class DependencyAuditCommandTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, string>, int, string, bool}> */
    public static function outcomes(): iterable
    {
        yield 'valid policy and permitted abandonment blocking' => ['normal', [], 0, '', true];
        yield 'resolution blocking disabled' => ['normal', ['COMPOSER_POLICY_ADVISORIES_BLOCK' => '0'], 1, 'COMPOSER_POLICY_ADVISORIES_BLOCK', false];
        yield 'legacy blocking override' => ['normal', ['COMPOSER_NO_SECURITY_BLOCKING' => '1'], 1, 'COMPOSER_NO_SECURITY_BLOCKING', false];
        yield 'abandonment report only' => ['normal', ['COMPOSER_AUDIT_ABANDONED' => 'report'], 1, 'COMPOSER_AUDIT_ABANDONED', false];
        yield 'development dependencies excluded' => ['normal', ['COMPOSER_NO_DEV' => '1'], 1, 'COMPOSER_NO_DEV', false];
        yield 'network disabled' => ['normal', ['COMPOSER_DISABLE_NETWORK' => '1'], 1, 'COMPOSER_DISABLE_NETWORK', false];
        yield 'alternate manifest' => ['normal', ['COMPOSER' => 'alternate.json'], 1, 'COMPOSER', false];
        yield 'arguments cannot reach Composer' => ['arguments', [], 2, 'Usage', false];
        yield 'unsupported Composer' => ['old version', [], 1, 'Composer 2.10.0', false];
        yield 'effective weakened malware policy' => ['malware', [], 1, 'malware.audit', false];
        yield 'merged advisory ignore' => ['ignore', [], 1, 'advisories.ignore-severity', false];
        yield 'legacy fail-open network handling' => ['unreachable', [], 1, 'unreachable source', false];
        yield 'external repository mirror' => ['mirror', [], 1, 'default Packagist advisory source', false];
        yield 'insecure transport' => ['tls', [], 1, 'secure HTTPS', false];
        yield 'missing lock stops the audit' => ['missing lock', [], 1, 'composer update', false];
        yield 'stale lock stops the audit' => ['stale lock', [], 2, 'stale', false];
        yield 'audit failure propagated' => ['audit failure', [], 3, 'failed', true];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('outcomes')]
    public function testEffectivePolicyAndAuditStatus(
        string $case,
        array $environment,
        int $exit,
        string $error,
        bool $auditRuns,
    ): void {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/pp-dependency-audit-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory . '/scripts/Tooling', 0700, true));
        try {
            foreach (['dependency-audit.php', 'integration-support.php', 'Tooling/autoload.php'] as $file) {
                self::assertTrue(copy($root . '/scripts/' . $file, $directory . '/scripts/' . $file));
            }
            if ($case !== 'missing lock') {
                self::assertTrue(touch($directory . '/composer.lock'));
            }
            self::assertNotFalse(file_put_contents($directory . '/composer', self::COMPOSER_FIXTURE));
            self::assertTrue(chmod($directory . '/composer', 0700));
            $command = [
                'env',
                '-i',
                'PATH=' . $directory . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin'),
                'FIXTURE_CASE=' . $case,
                'COMPOSER_POLICY_ABANDONED_BLOCK=0',
            ];
            foreach ($environment as $name => $value) {
                $command[] = $name . '=' . $value;
            }
            $command[] = PHP_BINARY;
            $command[] = $directory . '/scripts/dependency-audit.php';
            if ($case === 'arguments') {
                $command[] = '--ignore-severity=high';
            }
            [$status, $output, $stderr] = execute($command, [], $root);
            self::assertSame($exit, $status, $output . $stderr);
            if ($error !== '') {
                self::assertStringContainsString($error, $stderr);
            }
            self::assertSame($auditRuns, is_file($directory . '/audit-ran'));
        } finally {
            [$cleanup] = execute(['rm', '-rf', '--', $directory]);
            self::assertSame(0, $cleanup);
        }
    }

    private const COMPOSER_FIXTURE = <<<'PHP'
        #!/usr/bin/env php
        <?php
        $case = getenv('FIXTURE_CASE');
        $args = array_slice($argv, 5);
        if (array_slice($argv, 1, 4) !== ['--no-interaction', '--no-plugins', '--no-scripts', '--no-ansi']) {
            exit(64);
        }
        switch ($args) {
            case ['--version']:
                echo 'Composer version ', $case === 'old version' ? '2.9.9' : '2.10.3', PHP_EOL;
                exit(0);
            case ['config', 'policy']:
                $advisories = ['block' => true, 'audit' => 'fail'];
                if ($case === 'ignore') {
                    $advisories['ignore-severity'] = ['high'];
                }
                echo json_encode([
                    'advisories' => $advisories,
                    'malware' => ['block' => true, 'audit' => $case === 'malware' ? 'report' : 'fail', 'block-scope' => 'all'],
                    'abandoned' => ['audit' => 'fail', 'block' => false],
                ]);
                exit(0);
            case ['config', 'audit']:
                echo json_encode(['ignore-unreachable' => $case === 'unreachable']);
                exit(0);
            case ['config', 'repositories']:
                echo json_encode(['packagist.org' => [
                    'type' => 'composer',
                    'url' => $case === 'mirror' ? 'https://mirror.example' : 'https://repo.packagist.org',
                ]]);
                exit(0);
            case ['config', 'disable-tls']:
                echo json_encode($case === 'tls');
                exit(0);
            case ['config', 'secure-http']:
                echo 'true';
                exit(0);
            case ['validate', '--strict', '--no-check-publish', '--check-lock']:
                if ($case === 'stale lock') {
                    fwrite(STDERR, "lock is stale\n");
                    exit(2);
                }
                exit(0);
            case ['audit', '--locked', '--abandoned=fail']:
                touch('audit-ran');
                if ($case === 'audit failure') {
                    fwrite(STDERR, "audit failed\n");
                    exit(3);
                }
                exit(0);
        }
        exit(64);
        PHP;
}
