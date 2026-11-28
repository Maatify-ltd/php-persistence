<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/release-artifact.php';

/** Tests the CLI's evidence boundary with a temporary tagged fixture and Composer stand-in. */
final class PublishedArtifactEvidenceTest extends TestCase
{
    private const SECRETS_MARKER = 'fixture-auth-value';
    private const EXTRA_PATH = "unexpected\nPAV RESULT FAIL.txt";

    /** @return iterable<string, array{string, int, string}> */
    public static function outcomes(): iterable
    {
        yield 'matching dist' => ['match', 0, 'complete'];
        yield 'observed source fallback' => ['fallback', 1, 'installation'];
        yield 'changed bytes' => ['changed', 1, 'content'];
        yield 'unsafe resolution metadata' => ['resolution', 1, 'resolution'];
        yield 'failed update with installed metadata' => ['update', 1, 'composer-update'];
        yield 'cleanup failure' => ['cleanup', 3, 'complete'];
        yield 'unknown Composer version' => ['version', 1, 'composer-version'];
    }

    #[DataProvider('outcomes')]
    public function testCliRetainsSafeEvidenceThroughCleanup(string $case, int $exit, string $stage): void
    {
        $root = dirname(__DIR__, 2);
        $workspace = sys_get_temp_dir() . '/pp-pav-cli-' . bin2hex(random_bytes(8));
        $repo = $workspace . '/repo';
        $bin = $workspace . '/bin';
        $temporary = $workspace . '/temp';
        $environment = [
            'PATH=' . $bin . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME=' . $workspace,
            'TMPDIR=' . $temporary,
            'GIT_CONFIG_NOSYSTEM=1',
            'GIT_CONFIG_GLOBAL=/dev/null',
            'COMPOSER_AUTH=' . self::SECRETS_MARKER,
        ];
        self::assertTrue(mkdir($repo, 0700, true));
        try {
            self::assertTrue(mkdir($bin, 0700));
            self::assertTrue(mkdir($temporary, 0700));
            $files = [...RELEASE_FILES, 'src/Fixture.php'];
            $tracked = [
                ...$files,
                'scripts/published-artifact.php',
                'scripts/release-artifact.php',
                'scripts/integration-support.php',
                'scripts/Tooling/autoload.php',
                'scripts/Tooling/IntegrationInterrupted.php',
                'scripts/Tooling/IntegrationResidueShadow.php',
                'scripts/Tooling/OrchestrationBudget.php',
            ];
            foreach ($tracked as $file) {
                $destination = $repo . '/' . $file;
                if (!is_dir(dirname($destination))) {
                    self::assertTrue(mkdir(dirname($destination), 0700, true));
                }
                if ($file === 'src/Fixture.php') {
                    self::assertNotFalse(file_put_contents($destination, "<?php\n"));
                } else {
                    self::assertTrue(copy($root . '/' . $file, $destination));
                }
            }
            self::assertNotFalse(file_put_contents($repo . '/.gitattributes', "/scripts export-ignore\n/.gitattributes export-ignore\n"));
            self::fixtureGit($repo, $environment, 'init', '--quiet');
            self::fixtureGit($repo, $environment, 'add', '--', '.gitattributes', ...$tracked);
            self::fixtureGit($repo, $environment, '-c', 'user.name=PAV Fixture', '-c', 'user.email=pav@example.invalid', 'commit', '-qm', 'Fixture');
            $sha = self::fixtureGit($repo, $environment, 'rev-parse', 'HEAD');
            self::fixtureGit($repo, $environment, 'update-ref', 'refs/tags/v1.0.0', $sha);

            self::createComposer($bin . '/composer', $repo, $sha, $case, $files);
            if ($case === 'cleanup') {
                self::assertNotFalse(file_put_contents($bin . '/rm', "#!/bin/sh\necho " . self::SECRETS_MARKER . " >&2\nexit 9\n"));
                self::assertTrue(chmod($bin . '/rm', 0700));
            }
            [$status, $output, $error] = execute([
                'env', '-i', ...$environment, PHP_BINARY, '-d', 'sys_temp_dir=' . $temporary,
                $repo . '/scripts/published-artifact.php', '--target=1.0.0', '--sha=' . $sha,
            ], [], $repo, 20);

            self::assertSame($exit, $status, $output . $error);
            $workspaces = glob($temporary . '/pp-published-*', GLOB_ONLYDIR);
            self::assertIsArray($workspaces);
            self::assertCount($case === 'cleanup' ? 1 : 0, $workspaces, $output . $error);
            self::assertStringNotContainsString(self::SECRETS_MARKER, $output . $error);
            self::assertStringNotContainsString(self::EXTRA_PATH, $output . $error);
            $records = self::records($output);
            self::assertSame('RESULT', array_key_last($records));
            self::assertSame($stage, $records['RESULT']['stage']);
            self::assertSame($exit, $records['RESULT']['exitCode']);
            self::assertSame($case === 'cleanup' ? 'failed' : 'verified', $records['RESULT']['cleanup']);
            self::assertSame($case === 'cleanup' ? 0 : $exit, $records['RESULT']['verificationExitCode']);
            self::assertSame($exit === 0 ? 'PASS' : 'NOT PASS', $records['RESULT']['integrity']);
            self::assertSame($exit === 0, str_contains($output, 'PAV VERIFICATION OK'));
            self::assertSame('dist', $records['EXPECTED']['installationSource']);

            if ($case === 'version') {
                self::assertNull($records['COMPOSER']['version']);
                self::assertArrayNotHasKey('RESOLVED', $records);

                return;
            }
            self::assertSame('2.10.3', $records['COMPOSER']['version']);
            self::assertSame(['v1.0.0'], $records['RESOLVED']['versions']);
            self::assertIsArray($records['RESOLVED']['dist']);
            self::assertSame($sha, $records['RESOLVED']['dist']['reference']);
            if ($case === 'resolution') {
                self::assertSame('[redacted]', $records['RESOLVED']['dist']['url']);
                self::assertArrayNotHasKey('OBSERVED', $records);

                return;
            }

            self::assertSame(1, $records['OBSERVED']['matchCount']);
            self::assertIsArray($records['OBSERVED']['packages']);
            $observed = $records['OBSERVED']['packages'][0] ?? null;
            self::assertIsArray($observed);
            self::assertSame('1.0.0', $observed['version']);
            self::assertSame('../maatify/php-persistence', $observed['installPath']);
            self::assertSame($case === 'fallback' ? 'source' : 'dist', $observed['installationSource']);
            self::assertIsArray($observed['source']);
            self::assertSame($sha, $observed['source']['reference']);
            if ($case === 'update') {
                self::assertSame(7, $records['OBSERVED']['composerExitCode']);
                self::assertSame(7, $records['RESULT']['composerExitCode']);
            }
            if ($case === 'update' || $case === 'fallback') {
                self::assertArrayNotHasKey('CONTENT', $records);

                return;
            }
            if ($case === 'changed') {
                self::assertIsArray($records['CONTENT']['changedFiles']);
                self::assertSame([
                    'expectedSha256' => hash_file('sha256', $repo . '/README.md'),
                    'observedSha256' => hash('sha256', 'altered'),
                ], $records['CONTENT']['changedFiles']['README.md']);
                self::assertSame([[
                    'pathSha256' => hash('sha256', self::EXTRA_PATH),
                    'observedSha256' => hash('sha256', 'extra'),
                ]], $records['CONTENT']['unexpectedFiles']);
            } else {
                self::assertIsArray($records['CONTENT']['matchingFiles']);
                self::assertCount(count($files), $records['CONTENT']['matchingFiles']);
            }
        } finally {
            [$cleanup] = execute(['rm', '-rf', '--', $workspace]);
            self::assertSame(0, $cleanup);
        }
    }

    /** @param list<string> $environment */
    private static function fixtureGit(string $repo, array $environment, string ...$arguments): string
    {
        [$status, $output, $error] = execute(['env', '-i', ...$environment, 'git', ...array_values($arguments)], [], $repo, 10);
        self::assertSame(0, $status, $error);

        return trim($output);
    }

    /** @param list<string> $files */
    private static function createComposer(string $path, string $repo, string $sha, string $case, array $files): void
    {
        $variables = compact('repo', 'sha', 'case', 'files') + ['secret' => self::SECRETS_MARKER, 'extraPath' => self::EXTRA_PATH];
        $script = <<<'PHP_SCRIPT'
$operation = $argv[4] ?? '';
fwrite(STDERR, $secret . "\n");
if (getenv('COMPOSER_AUTH') !== false || array_slice($argv, 1, 3) !== ['--no-interaction', '--no-plugins', '--no-scripts']) {
    exit(8);
}
if ($operation === '--version') {
    echo $case === 'version' ? $secret : "Composer version 2.10.3\n";
    exit;
}
$package = [
    'name' => 'maatify/php-persistence',
    'source' => ['type' => 'git', 'url' => 'https://github.com/Maatify-ltd/php-persistence.git', 'reference' => $sha],
    'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/Maatify-ltd/php-persistence/zipball/' . $sha, 'reference' => $sha],
];
if ($operation === 'show') {
    if ($case === 'resolution') {
        $package['dist']['url'] = 'https://user:' . $secret . '@example.invalid/file.zip';
    }
    echo json_encode($package + ['versions' => ['v1.0.0']], JSON_THROW_ON_ERROR);
    exit;
}
if ($operation !== 'update') {
    exit(8);
}
$installed = 'vendor/maatify/php-persistence';
foreach ($files as $file) {
    $destination = $installed . '/' . $file;
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0700, true);
    }
    copy($repo . '/' . $file, $destination);
}
if ($case === 'changed') {
    file_put_contents($installed . '/README.md', 'altered');
    file_put_contents($installed . '/' . $extraPath, 'extra');
}
mkdir('vendor/composer', 0700);
$package += [
    'version' => '1.0.0',
    'install-path' => '../maatify/php-persistence',
    'installation-source' => $case === 'fallback' ? 'source' : 'dist',
];
file_put_contents('vendor/composer/installed.json', json_encode(['packages' => [$package]], JSON_THROW_ON_ERROR));
exit($case === 'update' ? 7 : 0);
PHP_SCRIPT;
        $prefix = "#!/usr/bin/env php\n<?php\nextract(" . var_export($variables, true) . ");\n";
        self::assertNotFalse(file_put_contents($path, $prefix . $script));
        self::assertTrue(chmod($path, 0700));
    }

    /** @return array<string, array<array-key, mixed>> */
    private static function records(string $output): array
    {
        $records = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^PAV ([A-Z]+) (\{.*\})$/D', $line, $record) !== 1) {
                continue;
            }
            $value = json_decode($record[2], true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($value);
            self::assertArrayNotHasKey($record[1], $records);
            $records[$record[1]] = $value;
        }

        return $records;
    }
}
