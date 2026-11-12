<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Protects the repository-owned Integration infrastructure contract without needing Docker.
 */
final class IntegrationInfrastructureTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::root() . '/' . $relative);
        self::assertIsString($contents, $relative);

        return $contents;
    }

    /**
     * @return array<string, array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool}>
     */
    private static function cells(): array
    {
        $data = json_decode(self::read('docker/integration-matrix.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['cells']);

        /** @var array<string, array{engine: string, image: string, tag: string, digest: string, expectedVersion: string, tier: string, platform: ?string, required: bool}> $cells */
        $cells = $data['cells'];

        return $cells;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     *
     * @return array{int, string, string}
     */
    private static function orchestrator(array $arguments, array $environment = []): array
    {
        $process = proc_open(
            [PHP_BINARY, self::root() . '/scripts/integration.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment === [] ? null : [...getenv(), ...$environment],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function testComposeDefinitionIsParameterizedAndCollisionSafe(): void
    {
        $compose = self::read('compose.integration.yaml');

        self::assertStringNotContainsString('container_name', $compose);
        self::assertStringNotContainsString('latest', $compose);
        self::assertStringNotContainsString('sleep', $compose);
        self::assertStringContainsString('${PP_DB_IMAGE:?', $compose);
        self::assertStringContainsString('"127.0.0.1::3306"', $compose, 'loopback only, dynamic host port');
        self::assertStringContainsString('healthcheck:', $compose);
        self::assertSame(1, preg_match_all('/^  [a-z]+:\s*$/m', $compose), 'a single service');
        self::assertSame(['compose.integration.yaml'], array_map('basename', glob(self::root() . '/{docker-compose,compose}*.y*ml', GLOB_BRACE) ?: []), 'exactly one Compose definition');
    }

    public function testMatrixPinsEveryImageByDigestAndVersion(): void
    {
        $cells = self::cells();
        self::assertNotSame([], $cells);

        foreach ($cells as $key => $cell) {
            self::assertSame($cell['engine'] . '-' . $cell['tag'], $key);
            self::assertContains($cell['image'], ['mysql', 'mariadb'], $key);
            self::assertSame($cell['image'], $cell['engine'], $key);
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $cell['tag'], $key);
            self::assertSame($cell['tag'], $cell['expectedVersion'], $key);
            self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $cell['digest'], $key);
            self::assertNotSame('latest', $cell['tag']);
        }
    }

    public function testMatrixHasOneOldestAndOneNewestEndpointPerEngineAndKeepsTheRatifiedMinimum(): void
    {
        $cells = self::cells();

        foreach (['mysql', 'mariadb'] as $engine) {
            foreach (['endpoint-oldest', 'endpoint-newest'] as $tier) {
                $matching = array_filter($cells, static fn(array $cell): bool => $cell['engine'] === $engine && $cell['tier'] === $tier);
                self::assertCount(1, $matching, $engine . ' ' . $tier);
            }
        }
        self::assertSame('8.0.16', $cells['mysql-8.0.16']['tag'], 'DEC-008 minimum must not be substituted');
        self::assertSame('endpoint-oldest', $cells['mysql-8.0.16']['tier']);
        self::assertSame('exploratory', $cells['mysql-26.10.0']['tier']);
        self::assertFalse($cells['mysql-26.10.0']['required']);
    }

    public function testDefaultCellExistsAndRequiredCellsAreAllInTheWorkflowMatrix(): void
    {
        $data = json_decode(self::read('docker/integration-matrix.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey($data['default'], self::cells());

        $workflow = self::read('.github/workflows/ci.yml');
        foreach (self::cells() as $key => $cell) {
            if ($cell['required']) {
                self::assertStringContainsString('cell: ' . $key . ',', $workflow, $key . ' must be in the CI matrix');
            }
        }
        foreach (['endpoint-oldest', 'endpoint-newest'] as $tier) {
            foreach (self::cells() as $key => $cell) {
                if ($cell['tier'] === $tier) {
                    self::assertStringContainsString('{ cell: ' . $key . ', php: "8.4" }', $workflow);
                    self::assertStringContainsString('{ cell: ' . $key . ', php: "8.5" }', $workflow);
                }
            }
        }
    }

    public function testRatifiedBoundaryRecordMatchesTheMatrix(): void
    {
        $decision = self::read('docs/decisions/DEC-013-test-only-database-version-boundary.md');

        self::assertStringContainsString('**Status:** `ACTIVE`', $decision);
        foreach (self::cells() as $cell) {
            self::assertStringContainsString('`' . $cell['tag'] . '`', $decision, $cell['tag'] . ' must be named in DEC-013');
        }
        self::assertStringContainsString('exploratory cell only', $decision);

        $open = self::read('docs/decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md');
        self::assertStringContainsString('**Status:** `PROPOSED`', $open);
        self::assertStringNotContainsString('9.7.4', $open, 'the ratified version rows are removed from the still-proposed record');
    }

    public function testWorkflowFollowsTheHardeningRules(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');
        $action = self::read('.github/actions/setup-php-composer/action.yml');

        foreach ([$workflow, $action] as $text) {
            self::assertStringNotContainsString('continue-on-error', $text);
            self::assertStringNotContainsString('|| true', $text);
            self::assertStringNotContainsString('ignore-platform-reqs', $text);
            self::assertStringNotContainsString('pull_request_target', $text);
            self::assertStringNotContainsString('secrets.', $text);
            self::assertStringNotContainsString('sleep ', $text);
        }
        self::assertMatchesRegularExpression('/^permissions:\n  contents: read\n/m', $workflow);
        self::assertStringContainsString('concurrency:', $workflow);
        self::assertStringContainsString('cancel-in-progress: ${{ github.event_name == \'pull_request\' }}', $workflow);

        preg_match_all('/^\s*(?:- )?uses:\s*(\S+)/m', $workflow . "\n" . $action, $uses);
        self::assertNotSame([], $uses[1]);
        foreach ($uses[1] as $reference) {
            if (str_starts_with($reference, './')) {
                continue;
            }
            self::assertMatchesRegularExpression('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $reference, 'external actions are pinned to a full commit SHA');
        }

        $jobSection = substr($workflow, (int) strpos($workflow, "\njobs:\n") + 7);
        $chunks = preg_split('/^  ([a-z-]+):\n/m', $jobSection, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($chunks);
        $names = [];
        for ($index = 0; $index + 1 < count($chunks); $index += 2) {
            $names[] = $chunks[$index];
            self::assertStringContainsString('timeout-minutes:', $chunks[$index + 1], $chunks[$index] . ' needs a timeout');
        }
        self::assertSame(['workflow-lint', 'static', 'compat', 'integration', 'examples-smoke', 'consumer-verification', 'exploratory', 'ci-gate'], $names);
    }

    private static function constantSeconds(string $source, string $name): int
    {
        self::assertSame(1, preg_match('/^const ' . $name . ' = (\d+);$/m', $source, $matches), $name);
        self::assertArrayHasKey(1, $matches);

        return (int) $matches[1];
    }

    private static function jobTimeoutMinutes(string $workflow, string $job): int
    {
        self::assertSame(1, preg_match('/^  ' . $job . ':\n(?:    .*\n|\n)*?    timeout-minutes: (\d+)/m', $workflow, $matches), $job);
        self::assertArrayHasKey(1, $matches);

        return (int) $matches[1];
    }

    public function testJobTimeoutsCoverTheOrchestrationBudgetTeardownAndSetup(): void
    {
        $support = self::read('scripts/integration-support.php');
        $needed = self::constantSeconds($support, 'TOTAL_BUDGET_SECONDS')
            + self::constantSeconds($support, 'TEARDOWN_TIMEOUT_SECONDS')
            + self::constantSeconds($support, 'TEARDOWN_MARGIN_SECONDS')
            + 600;

        $workflow = self::read('.github/workflows/ci.yml');
        foreach (['integration', 'examples-smoke', 'consumer-verification', 'exploratory'] as $job) {
            self::assertGreaterThanOrEqual(
                $needed,
                self::jobTimeoutMinutes($workflow, $job) * 60,
                $job . ' job timeout must exceed the orchestration budget, the teardown reserve and 10 minutes of setup',
            );
        }
    }

    #[DataProvider('suiteDeadlines')]
    public function testSuiteDeadlinePreservesBothBudgets(?float $overall, float $now, int $suite, ?float $expected): void
    {
        require_once self::root() . '/scripts/integration-support.php';

        self::assertSame($expected, suiteDeadline($overall, $now, $suite, 210));
    }

    /** @return array<string, array{?float, float, int, ?float}> */
    public static function suiteDeadlines(): array
    {
        return [
            'overall budget is tighter' => [322.5, 100.25, 30, 322.5],
            'suite budget is tighter' => [400.0, 100.25, 30, 340.25],
            'equal budgets' => [340.25, 100.25, 30, 340.25],
            'no overall budget' => [null, 100.25, 30, 340.25],
            'one whole second remains' => [311.25, 100.25, 30, 311.25],
            'second cycle has subsecond work budget' => [322.5, 111.75, 30, null],
            'second cycle has exhausted work budget' => [322.5, 112.5, 30, null],
        ];
    }

    public function testGateInspectsEveryRequiredJob(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');

        [, $gate] = array_pad(explode("\n  ci-gate:\n", $workflow, 2), 2, '');
        self::assertMatchesRegularExpression(
            '/^    needs: \[workflow-lint, static, compat, integration, examples-smoke, consumer-verification\]$/m',
            $gate,
        );
        self::assertStringNotContainsString('exploratory', $gate, 'the exploratory job is not required');
        self::assertStringContainsString('name: CI gate', $gate);
        self::assertStringContainsString('if: always()', $gate);
        self::assertStringContainsString('RESULTS: ${{ toJSON(needs) }}', $gate);
        self::assertStringContainsString('set -euo pipefail', $gate);
        self::assertStringContainsString('echo "$RESULTS" | jq -e \'all(.[]; .result == "success")\' > /dev/null', $gate);
    }

    public function testComposerAndToolingAreProvisionedDeterministically(): void
    {
        $action = self::read('.github/actions/setup-php-composer/action.yml');

        self::assertStringContainsString('COMPOSER_VERSION: 2.10.3', $action);
        self::assertStringContainsString('7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6', $action);
        self::assertStringContainsString('sha256sum --check --strict', $action);
        self::assertStringContainsString('tools: none', $action);
        self::assertStringContainsString('pdo_mysql', $action);
        self::assertStringContainsString('ACTIONLINT_SHA256', self::read('.github/workflows/ci.yml'));
    }

    public function testOrchestratorRejectsUnknownCellsAndOptions(): void
    {
        [$status, , $errors] = self::orchestrator(['--cell=mysql-latest']);
        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown cell "mysql-latest"', $errors);
        self::assertStringContainsString('mysql-8.0.16', $errors);

        [$status, , $errors] = self::orchestrator(['--image=mysql:latest']);
        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown or invalid option', $errors);

        [$status] = self::orchestrator(['--fresh-runs=0']);
        self::assertSame(2, $status);
    }

    public function testOrchestratorFailsClearlyWhenDockerIsUnavailable(): void
    {
        [$status, , $errors] = self::orchestrator(['--cell=mysql-8.4.13'], ['PATH' => '/nonexistent']);

        self::assertSame(3, $status);
        self::assertStringContainsString('Prerequisite failed', $errors);
        self::assertStringContainsString('docker', $errors);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documentedCommands(): array
    {
        return [
            'integration entry' => ['composer test:integration'],
            'a specific cell' => ['PP_DB_CELL='],
            'fresh runs option' => ['--fresh-runs='],
        ];
    }

    #[DataProvider('documentedCommands')]
    public function testCanonicalCommandsAreDocumented(string $needle): void
    {
        self::assertStringContainsString($needle, self::read('docs/architecture/CI_AND_INTEGRATION.md'));
    }
}
