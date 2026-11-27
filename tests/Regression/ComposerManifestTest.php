<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Protects the durable Composer contract recorded in the ACTIVE decisions.
 */
final class ComposerManifestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $path = dirname(__DIR__, 2) . '/composer.json';
        $json = file_get_contents($path);
        self::assertIsString($json);

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('composer.json is not valid JSON: ' . $exception->getMessage());
        }

        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function testIdentityLicenseAndTypeMatchTheDecisions(): void
    {
        $manifest = self::manifest();

        self::assertSame('maatify/php-persistence', $manifest['name']);
        self::assertSame('library', $manifest['type']);
        self::assertSame('proprietary', $manifest['license']);

        $license = file_get_contents(dirname(__DIR__, 2) . '/LICENSE');
        self::assertIsString($license);
        self::assertStringStartsWith('Maatify Proprietary License', $license);
    }

    public function testAutoloadMappingsAreSeparatedAndPackageSpecific(): void
    {
        $manifest = self::manifest();

        self::assertSame(
            ['psr-4' => ['Maatify\PhpPersistence\\' => 'src/']],
            $manifest['autoload'],
        );
        self::assertSame(
            ['psr-4' => ['Maatify\PhpPersistence\Tests\\' => 'tests/']],
            $manifest['autoload-dev'],
        );
    }

    public function testRuntimeRequirementsIncludeThePdoMysqlDriver(): void
    {
        $manifest = self::manifest();
        $require = $manifest['require'];

        self::assertIsArray($require);
        self::assertSame('>=8.4.27 <8.5.0 || >=8.5.12 <9.0.0', $require['php']);
        self::assertSame('*', $require['ext-pdo']);
        self::assertSame('*', $require['ext-pdo_mysql']);
        self::assertArrayHasKey('maatify/exceptions', $require);
    }

    public function testDriverExtensionsDeclaredAsRequiredAreLoaded(): void
    {
        self::assertTrue(extension_loaded('pdo'));
        self::assertTrue(extension_loaded('pdo_mysql'));
        self::assertContains('mysql', \PDO::getAvailableDrivers());
    }

    public function testNoCoexistenceBreakingFieldsOrCommittedVersion(): void
    {
        $manifest = self::manifest();

        foreach (['replace', 'provide', 'conflict', 'version', 'time'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $manifest, $forbidden . ' must not be declared');
        }
    }

    public function testStabilityPolicyAndLockFilePolicy(): void
    {
        $manifest = self::manifest();

        self::assertSame('stable', $manifest['minimum-stability']);
        self::assertTrue($manifest['prefer-stable']);

        $config = $manifest['config'];
        self::assertIsArray($config);
        self::assertTrue($config['sort-packages']);
        self::assertTrue($config['optimize-autoloader']);
        self::assertSame(['php' => '8.4.27'], $config['platform']);
        self::assertArrayNotHasKey('secure-http', $config);
        self::assertArrayNotHasKey('allow-plugins', $config);

        $ignore = file_get_contents(dirname(__DIR__, 2) . '/.gitignore');
        self::assertIsString($ignore);
        self::assertStringContainsString('composer.lock', $ignore);
        self::assertStringContainsString('/vendor/', $ignore);
    }

    public function testDependencyPolicyIsFailClosed(): void
    {
        $config = self::manifest()['config'];
        self::assertIsArray($config);

        $expected = [
            'advisories' => ['block' => true, 'audit' => 'fail'],
            'malware' => ['block' => true, 'block-scope' => 'all', 'audit' => 'fail'],
            'abandoned' => ['audit' => 'fail'],
        ];

        self::assertSame($expected, $config['policy']);
        self::assertArrayNotHasKey('audit', $config, 'the legacy audit configuration must not be mixed with policy');
    }

    public function testPackageMapsAreAlphabeticallySorted(): void
    {
        $manifest = self::manifest();

        foreach (['require', 'require-dev'] as $section) {
            $map = $manifest[$section];
            self::assertIsArray($map);
            $keys = array_keys($map);
            $sorted = $keys;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $keys, $section . ' must be sorted');
        }
    }

    public function testScriptsReferenceOnlyExistingSuitesAndTheCanonicalIntegrationEntry(): void
    {
        $manifest = self::manifest();
        $scripts = $manifest['scripts'];
        self::assertIsArray($scripts);

        foreach (['analyse', 'format', 'format:check', 'syntax', 'whitespace', 'test', 'test:unit', 'test:regression', 'test:integration'] as $name) {
            self::assertArrayHasKey($name, $scripts);
        }
        self::assertSame(['@test:unit', '@test:regression', '@test:integration'], $scripts['test']);
        self::assertSame('@php scripts/integration.php', $scripts['test:integration']);
        self::assertSame('bash scripts/check-php-constraint.sh', $scripts['verify:php-constraint']);
        self::assertSame('phpunit --testsuite unit', $scripts['test:unit']);
        self::assertSame('phpunit --testsuite regression', $scripts['test:regression']);

        $xml = file_get_contents(dirname(__DIR__, 2) . '/phpunit.xml');
        self::assertIsString($xml);
        foreach (['unit', 'regression', 'integration'] as $suite) {
            self::assertStringContainsString('<testsuite name="' . $suite . '">', $xml);
        }
        self::assertStringContainsString('failOnSkipped="true"', $xml);
    }
}
