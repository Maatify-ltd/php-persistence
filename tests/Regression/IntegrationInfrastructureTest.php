<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
                    self::assertStringContainsString('{ cell: ' . $key . ', php: "8.6" }', $workflow);
                }
            }
        }
    }

    public function testPhp86IsQualifiedInEveryRequiredBoundaryWithoutRemovingEarlierMinors(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');

        self::assertStringContainsString('php: ["8.4", "8.5", "8.6"]', $workflow, 'Compat covers the minimum, the intermediate and the latest minor');
        $integration = substr($workflow, (int) strpos($workflow, "\n  integration:\n"), (int) strpos($workflow, "\n  examples-smoke:\n") - (int) strpos($workflow, "\n  integration:\n"));
        self::assertSame(15, preg_match_all('/^          - \\{ cell: [a-z0-9.-]+, php: "8\\.[456]" \\}$/m', $integration), 'Integration keeps the 11 existing cells and adds four PHP 8.6 cells');
        self::assertSame(4, preg_match_all('/php: "8\\.6" \\}$/m', $integration));
        foreach (['mysql-8.4.13', 'mariadb-11.8.9'] as $cell) {
            self::assertStringContainsString('{ cell: ' . $cell . ', php: "8.4" }', $workflow);
            self::assertStringContainsString('{ cell: ' . $cell . ', php: "8.6" }', $workflow);
        }
        self::assertStringContainsString('php: ["8.4", "8.6"]', $workflow, 'Consumer verification runs on the minimum and the latest minor');
        self::assertSame(0, preg_match('/php-version: "8\.[0-9]"/', substr($workflow, (int) strpos($workflow, '  examples-smoke:'), (int) strpos($workflow, '  release-artifact:') - (int) strpos($workflow, '  examples-smoke:'))), 'smoke and consumer jobs take the PHP minor from their matrix');
    }

    public function testFormatterRunsOnlyOnItsSupportedHostsAndTheVerifierIsRequiredOnPhp86(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');

        self::assertStringContainsString("        if: matrix.php != '8.6'\n        run: composer format:check\n", $workflow);
        self::assertStringContainsString("        if: matrix.php == '8.6'\n        run: php scripts/check-per-cs-3-1.php\n", $workflow);
        self::assertSame(1, substr_count($workflow, 'composer format:check'), 'the full local command keeps its meaning');
        self::assertStringNotContainsString('allow-unsupported-php-version', $workflow);
        self::assertStringNotContainsString('PHP_CS_FIXER_IGNORE_ENV', $workflow);
        self::assertStringNotContainsString('setUnsupportedPhpVersionAllowed', self::read('.php-cs-fixer.php'));
        self::assertStringContainsString('"php-cs-fixer fix --dry-run --diff"', self::read('composer.json'));
        self::assertStringContainsString('"@php scripts/check-per-cs-3-1.php"', self::read('composer.json'));
    }

    public function testSetupActionProvesANativeStableCliAndTheHarnessExtensions(): void
    {
        $action = self::read('.github/actions/setup-php-composer/action.yml');

        self::assertSame(1, substr_count($action, "      if: inputs.php-version != '8.6'\n      # shivammathur/setup-php 2.40.0\n      uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962\n"), '8.4 and 8.5 stay on the qualified 2.40.0 release');
        self::assertSame(1, substr_count($action, "      if: inputs.php-version == '8.6'\n"), 'only 8.6 uses the second pin');
        self::assertStringContainsString('uses: shivammathur/setup-php@2001cd3148dcbaf467b7fa06cd7155ebb6d4cacf', $action);
        self::assertSame(2, substr_count($action, 'uses: shivammathur/setup-php@'), 'exactly two pins');
        self::assertStringContainsString('Review condition: when a tagged setup-php release that contains 2001cd31 exists', $action);
        self::assertStringContainsString('43 commits ahead of 2.40.0', $action);
        self::assertSame(2, substr_count($action, 'pcntl, posix'));
        self::assertStringContainsString('is not a stable release of the requested minor', $action);
        self::assertStringContainsString('test "$(uname -m)" = x86_64', $action);
        self::assertStringContainsString('for extension in PDO pdo_mysql pcntl posix', $action);
    }

    public function testPhpSyntaxIsARequiredCheckOnEveryCompatMinor(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');
        $compat = substr($workflow, (int) strpos($workflow, "\n  compat:\n"), (int) strpos($workflow, "\n  integration:\n") - (int) strpos($workflow, "\n  compat:\n"));

        self::assertStringContainsString("      - name: PHP syntax on this PHP minor\n        run: composer syntax\n", $compat, 'composer syntax runs on 8.4, 8.5 and 8.6');
        self::assertStringNotContainsString("PHP syntax on this PHP minor\n        if:", $compat);
        self::assertStringContainsString("      - name: PHP syntax\n        run: composer syntax\n", $workflow, 'the Static checks job keeps the minimum-minor syntax check');
    }

    public function testRatifiedBoundaryRecordMatchesTheMatrix(): void
    {
        $decision = self::read('docs/decisions/DEC-013-test-only-database-version-boundary.md');

        self::assertStringContainsString('**Status:** `ACTIVE`', $decision);
        foreach (self::cells() as $cell) {
            self::assertStringContainsString('`' . $cell['tag'] . '`', $decision, $cell['tag'] . ' must be named in DEC-013');
        }
        self::assertStringContainsString('exploratory cell only', $decision);

        $protection = self::read('docs/decisions/DEC-009-proposed-version-matrix-and-mariadb-snapshot-isolation.md');
        self::assertStringContainsString('**Status:** ACTIVE', $protection);
        self::assertStringNotContainsString('9.7.4', $protection, 'DEC-013 owns the database version matrix');
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
        self::assertSame(['workflow-lint', 'static', 'compat', 'integration', 'examples-smoke', 'consumer-verification', 'release-artifact', 'exploratory', 'ci-gate'], $names);
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
        $budget = self::constantSeconds($support, 'TOTAL_BUDGET_SECONDS');
        $needed = $budget
            + self::constantSeconds($support, 'TEARDOWN_TIMEOUT_SECONDS')
            + self::constantSeconds($support, 'TEARDOWN_MARGIN_SECONDS')
            + 600;

        $workflow = self::read('.github/workflows/ci.yml');
        if (preg_match(
            "/^          COMPOSER_PROCESS_TIMEOUT: '([0-9]+)'\n        run: composer test:integration -- --fresh-runs=2$/m",
            $workflow,
            $composer,
        ) !== 1) {
            self::fail('The required database matrix must let its own orchestration deadline control teardown.');
        }
        self::assertGreaterThan($budget, (int) $composer[1]);

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
            '/^    needs: \[workflow-lint, static, compat, integration, examples-smoke, consumer-verification, release-artifact\]$/m',
            $gate,
        );
        self::assertStringNotContainsString('exploratory', $gate, 'the exploratory job is not required');
        self::assertStringContainsString('name: CI gate', $gate);
        self::assertStringContainsString('if: always()', $gate);
        self::assertStringContainsString('RESULTS: ${{ toJSON(needs) }}', $gate);
        self::assertStringContainsString('set -euo pipefail', $gate);
        self::assertStringContainsString('echo "$RESULTS" | jq -e \'all(.[]; .result == "success")\' > /dev/null', $gate);
    }

    public function testReleaseArtifactGateVerifiesTheStableHeadAndHistoricalRcTag(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');
        [, $following] = array_pad(explode("\n  release-artifact:\n", $workflow, 2), 2, '');
        [$job] = explode("\n  exploratory:\n", $following, 2);
        $verificationSteps = explode('          ref: refs/tags/v1.0.0-rc.2', $job, 2);
        self::assertCount(2, $verificationSteps);
        [$candidate, $historical] = $verificationSteps;
        $head = '${{ github.event.pull_request.head.sha || github.sha }}';
        $headCheck = 'test "$(git rev-parse HEAD)" = "$ARTIFACT_SHA"';

        self::assertStringContainsString('ref: ' . $head, $candidate);
        self::assertStringContainsString('ARTIFACT_SHA: ' . $head, $candidate);
        self::assertStringContainsString('set -euo pipefail', $candidate);
        self::assertStringContainsString($headCheck, $candidate);
        self::assertStringContainsString('composer release:verify -- --target=1.0.0 --sha="$ARTIFACT_SHA"', $candidate);
        self::assertStringNotContainsString('working-directory: published-rc2', $candidate);
        self::assertStringContainsString('path: published-rc2', $historical);
        self::assertStringContainsString('working-directory: published-rc2', $historical);
        self::assertStringContainsString('ARTIFACT_SHA: 71dc61548e48fd053376db69149a00bc87069b70', $historical);
        self::assertStringContainsString('set -euo pipefail', $historical);
        self::assertStringContainsString($headCheck, $historical);
        self::assertStringContainsString('composer release:verify -- --target=1.0.0-rc.2 --sha="$ARTIFACT_SHA"', $historical);
        self::assertSame(2, substr_count($job, 'persist-credentials: false'));
        self::assertStringContainsString('"release:verify": "@php scripts/release-artifact.php"', self::read('composer.json'));
    }

    public function testPublishedVerificationAcceptsOnlyTheStableTarget(): void
    {
        require_once self::root() . '/scripts/published-artifact.php';

        self::assertTrue(publishedVersion('1.0.0'));
        self::assertTrue(publishedVersion('v1.0.0'));
        self::assertFalse(publishedVersion('1.0.0-rc.2'));
        self::assertFalse(publishedVersion('1.0.0.0'));
        self::assertFalse(publishedVersion(' 1.0.0'));
        self::assertFalse(publishedVersion(null));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unqualifiedPublishedReferences(): iterable
    {
        yield 'wrong dist reference' => [['dist' => ['reference' => str_repeat('b', 40)]]];
        yield 'wrong source reference' => [['source' => ['reference' => str_repeat('b', 40)]]];
        yield 'missing dist' => [['dist' => null]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('unqualifiedPublishedReferences')]
    public function testPublishedVerificationRequiresBothQualifiedReferences(array $overrides): void
    {
        require_once self::root() . '/scripts/published-artifact.php';
        $sha = str_repeat('a', 40);
        $metadata = self::publishedPackageFixture($sha) + ['versions' => ['v1.0.0']];

        self::assertSame('v1.0.0', publishedResolvedVersion($metadata, $sha));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Packagist must expose GitHub dist and source for the exact qualified SHA');
        publishedResolvedVersion(array_replace_recursive($metadata, $overrides), $sha);
    }

    public function testPublishedVerificationRejectsObservedSourceFallback(): void
    {
        require_once self::root() . '/scripts/published-artifact.php';
        $sha = str_repeat('a', 40);
        $package = self::publishedPackageFixture($sha);

        self::assertSame($package, publishedInstalledPackage(['packages' => [$package]], $sha));

        $package['installation-source'] = 'source';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Composer did not install the exact release from dist');
        publishedInstalledPackage(['packages' => [$package]], $sha);
    }

    /**
     * @return array{
     *     name: string,
     *     version: string,
     *     installation-source: string,
     *     install-path: string,
     *     source: array{type: string, url: string, reference: string},
     *     dist: array{type: string, url: string, reference: string},
     * }
     */
    private static function publishedPackageFixture(string $sha): array
    {
        return [
            'name' => 'maatify/php-persistence',
            'version' => 'v1.0.0',
            'installation-source' => 'dist',
            'install-path' => '../maatify/php-persistence',
            'source' => ['type' => 'git', 'url' => 'https://github.com/Maatify-ltd/php-persistence.git', 'reference' => $sha],
            'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/Maatify-ltd/php-persistence/zipball/' . $sha, 'reference' => $sha],
        ];
    }

    /** @return iterable<string, array{array<string, ?string>, array<string, ?string>, ?string}> */
    public static function publishedContentCases(): iterable
    {
        yield 'matching artifacts' => [[], [], null];
        yield 'new required example absent from both' => [
            ['examples/ordering_unique_usage.php' => null],
            ['examples/ordering_unique_usage.php' => null],
            'The qualified reference omits a required file: examples/ordering_unique_usage.php',
        ];
        yield 'installed example omitted' => [
            [],
            ['examples/pagination_keyset_usage.php' => null],
            'Installed dist differs from the qualified archive: examples/pagination_keyset_usage.php',
        ];
        yield 'installed bytes changed' => [
            [],
            ['README.md' => 'This content differs.'],
            'Installed dist differs from the qualified archive: README.md',
        ];
        yield 'extra installed file' => [
            [],
            ['unexpected.txt' => 'Unexpected dist content.'],
            'Installed dist differs from the qualified archive: unexpected.txt',
        ];
        yield 'matching but stale presentation' => [
            ['README.md' => 'Old release identity.'],
            ['README.md' => 'Old release identity.'],
            'Archived README is missing the exact Stable target',
        ];
    }

    /**
     * @param array<string, ?string> $referenceOverrides
     * @param array<string, ?string> $installedOverrides
     */
    #[DataProvider('publishedContentCases')]
    public function testPublishedVerificationChecksRequiredFilesBytesAndPresentation(
        array $referenceOverrides,
        array $installedOverrides,
        ?string $expectedError,
    ): void {
        require_once self::root() . '/scripts/published-artifact.php';
        $workspace = sys_get_temp_dir() . '/pp-pav-test-' . bin2hex(random_bytes(8));
        $runtime = 'src/PavFixture.php';
        $files = [$runtime => '<?php'];
        foreach (RELEASE_FILES as $relative) {
            $files[$relative] = self::read($relative);
        }

        try {
            foreach (['qualified' => $referenceOverrides, 'installed' => $installedOverrides] as $name => $overrides) {
                foreach (array_replace($files, $overrides) as $relative => $contents) {
                    if ($contents === null) {
                        continue;
                    }
                    $path = $workspace . '/' . $name . '/' . $relative;
                    if (!is_dir(dirname($path))) {
                        self::assertTrue(mkdir(dirname($path), 0700, true));
                    }
                    self::assertSame(strlen($contents), file_put_contents($path, $contents));
                }
            }

            if ($expectedError !== null) {
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage($expectedError);
            }
            $actual = publishedContents($workspace . '/qualified', $workspace . '/installed', [$runtime]);
            $expected = array_map(static fn(string $contents): string => hash('sha256', $contents), $files);
            ksort($expected);
            self::assertSame($expected, $actual);
        } finally {
            [$cleanup] = execute(['rm', '-rf', '--', $workspace]);
            self::assertSame(0, $cleanup);
        }
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
