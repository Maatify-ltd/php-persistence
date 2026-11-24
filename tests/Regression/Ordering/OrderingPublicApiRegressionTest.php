<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression\Ordering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingConfigurationException;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Repository\ProtectedScopedOrderingManager;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Golden reflection contract for the Ordering capability (CONTRACT_MATRIX.md §6.2, §6.3), ported from
 * legacy's tests/Regression/Pdo/Ordering/OrderingPublicApiRegressionTest.php (1f6a7aa) with a per-class
 * namespace map replacing legacy's single shared-namespace assertion (DEC-004/010/011), the same pattern
 * WU-7 used for Pagination's own regression test.
 */
final class OrderingPublicApiRegressionTest extends TestCase
{
    public function testScopedOrderingConfigApiMatchesRepositoryReality(): void
    {
        $class = new ReflectionClass(ScopedOrderingConfig::class);

        self::assertSame('Maatify\\PhpPersistence\\Ordering\\Config', $class->getNamespaceName());
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertConstructor(ScopedOrderingConfig::class, [
            ['table', 'string', false, false, null],
            ['scopeColumn', 'string', true, true, null],
            ['idColumn', 'string', false, true, 'id'],
            ['orderColumn', 'string', false, true, 'display_order'],
            ['deletedAtColumn', 'string', true, true, 'deleted_at'],
            ['nullableScope', 'bool', false, true, false],
            ['updatedAtColumn', 'string', true, true, null],
        ]);

        foreach (['table', 'scopeColumn', 'idColumn', 'orderColumn', 'deletedAtColumn', 'nullableScope', 'updatedAtColumn'] as $property) {
            $reflectionProperty = $class->getProperty($property);
            self::assertTrue($reflectionProperty->isPublic());
            self::assertTrue($reflectionProperty->isPromoted());
        }

        self::assertMethod(ScopedOrderingConfig::class, 'quotedTable', [], 'string');
        self::assertMethod(ScopedOrderingConfig::class, 'quotedIdColumn', [], 'string');
        self::assertMethod(ScopedOrderingConfig::class, 'quotedOrderColumn', [], 'string');
        self::assertMethod(ScopedOrderingConfig::class, 'quotedScopeColumn', [], 'string');
        self::assertMethod(ScopedOrderingConfig::class, 'quotedDeletedAtColumn', [], 'string');
        self::assertMethod(ScopedOrderingConfig::class, 'quotedUpdatedAtColumn', [], 'string');
    }

    public function testScopedOrderingManagerApiMatchesRepositoryReality(): void
    {
        $class = new ReflectionClass(ScopedOrderingManager::class);

        self::assertSame('Maatify\\PhpPersistence\\Ordering\\Repository', $class->getNamespaceName());
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertNull($class->getConstructor());

        self::assertMethod(ScopedOrderingManager::class, 'getNextPosition', [
            ['pdo', PDO::class, false, false, null],
            ['config', ScopedOrderingConfig::class, false, false, null],
            ['scopeValue', 'int|string', true, true, null],
        ], 'int');
        self::assertMethod(ScopedOrderingManager::class, 'getNextPositionForUpdate', [
            ['pdo', PDO::class, false, false, null],
            ['config', ScopedOrderingConfig::class, false, false, null],
            ['scopeValue', 'int|string', true, true, null],
            ['maxTailReads', 'int', false, true, 64],
        ], 'int');
        self::assertMethod(ScopedOrderingManager::class, 'moveWithinScope', [
            ['pdo', PDO::class, false, false, null],
            ['config', ScopedOrderingConfig::class, false, false, null],
            ['scopeValue', 'int|string', true, false, null],
            ['id', 'int', false, false, null],
            ['newOrder', 'int', false, false, null],
            ['updatedAtValue', 'string', true, true, null],
        ], 'bool');
        self::assertMethod(ScopedOrderingManager::class, 'rowExistsInScope', [
            ['pdo', PDO::class, false, false, null],
            ['config', ScopedOrderingConfig::class, false, false, null],
            ['scopeValue', 'int|string', true, false, null],
            ['id', 'int', false, false, null],
        ], 'bool');
    }

    public function testProtectedB1NamedCallsAreAdditive(): void
    {
        $manager = ProtectedScopedOrderingManager::class;
        $host = ['checkout', PdoProtectedTransactionCheckout::class, false, false, null];
        $legacy = ['config', ScopedOrderingConfig::class, false, false, null];
        $legacyScope = ['scopeValue', 'int|string', true, false, null];
        $id = ['id', 'int', false, false, null];
        $newOrder = ['newOrder', 'int', false, false, null];
        self::assertMethod($manager, 'getNextPositionForUpdate', [
            $host, $legacy, ['scopeValue', 'int|string', true, true, null], ['maxTailReads', 'int', false, true, 64],
        ], 'int');
        self::assertMethod($manager, 'moveWithinScope', [
            $host, $legacy, $legacyScope, $id, $newOrder, ['updatedAtValue', 'string', true, true, null],
        ], 'bool');

        $config = new ReflectionClass(UniqueOrderingConfig::class);
        self::assertTrue($config->isFinal());
        self::assertTrue($config->isReadOnly());
        self::assertConstructor(UniqueOrderingConfig::class, [
            ['ordering', ScopedOrderingConfig::class, false, false, null],
            ['anchorTable', 'string', false, false, null],
            ['anchorIdColumn', 'string', false, false, null],
            ['globalAnchorId', 'int', true, false, null],
            ['maxPosition', 'int', false, false, null],
            ['maxScopeRows', 'int', false, false, null],
            ['schemaAttestation', 'array', false, false, null],
            ['activeSchemaRevision', 'string', false, false, null],
        ]);
        $b1 = ['config', UniqueOrderingConfig::class, false, false, null];
        $scope = ['scopeId', 'int', true, false, null];
        self::assertMethod($manager, 'getNextUniquePositionForAppend', [$host, $b1, $scope], 'int');
        self::assertMethod($manager, 'moveUniqueWithinScope', [$host, $b1, $scope, $id, $newOrder], 'bool');
        self::assertMethod($manager, 'deleteUniqueWithinScope', [
            $host, $b1, $scope, $id, ['deleteCommandId', 'string', false, false, null],
        ], 'bool');
    }

    public function testBothNextPositionReadsRemainSupportedAndNotDeprecated(): void
    {
        $plainDocComment = (string) (new ReflectionMethod(ScopedOrderingManager::class, 'getNextPosition'))->getDocComment();
        $lockingDocComment = (string) (new ReflectionMethod(ScopedOrderingManager::class, 'getNextPositionForUpdate'))->getDocComment();

        self::assertStringNotContainsString('@deprecated', $plainDocComment);
        self::assertStringNotContainsString('@deprecated', $lockingDocComment);
        self::assertStringContainsString('getNextPositionForUpdate()', $plainDocComment);
    }

    public function testExceptionTypesSitUnderTheOrderingExceptionFolder(): void
    {
        self::assertSame(
            'Maatify\\PhpPersistence\\Ordering\\Exception',
            (new ReflectionClass(InvalidOrderingConfigurationException::class))->getNamespaceName(),
        );
        self::assertSame(
            'Maatify\\PhpPersistence\\Ordering\\Exception',
            (new ReflectionClass(InvalidOrderingOperationException::class))->getNamespaceName(),
        );
    }

    /**
     * @param class-string<object> $className
     * @param list<array{string, string, bool, bool, mixed}> $expected
     */
    private static function assertConstructor(string $className, array $expected): void
    {
        $constructor = (new ReflectionClass($className))->getConstructor();
        self::assertNotNull($constructor);
        self::assertParameters($constructor->getParameters(), $expected);
    }

    /**
     * @param class-string<object> $className
     * @param list<array{string, string, bool, bool, mixed}> $expectedParameters
     */
    private static function assertMethod(string $className, string $methodName, array $expectedParameters, string $returnType): void
    {
        $method = (new ReflectionClass($className))->getMethod($methodName);
        self::assertTrue($method->isPublic());
        self::assertParameters($method->getParameters(), $expectedParameters);
        self::assertSame($returnType, self::typeName($method->getReturnType()));
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param list<array{string, string, bool, bool, mixed}> $expected
     */
    private static function assertParameters(array $parameters, array $expected): void
    {
        self::assertCount(count($expected), $parameters);

        foreach ($expected as $index => [$name, $type, $allowsNull, $hasDefault, $default]) {
            $parameter = $parameters[$index];
            self::assertSame($name, $parameter->getName());
            self::assertSame($type, self::typeName($parameter->getType()));
            self::assertSame($allowsNull, $parameter->allowsNull());
            self::assertSame($hasDefault, $parameter->isDefaultValueAvailable());
            if ($hasDefault) {
                self::assertSame($default, $parameter->getDefaultValue());
            }
        }
    }

    private static function typeName(?ReflectionType $type): string
    {
        self::assertNotNull($type);

        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $innerType) {
                $name = self::typeName($innerType);
                if ($name !== 'null') {
                    $parts[] = $name;
                }
            }
            sort($parts);

            return implode('|', $parts);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = [];
            foreach ($type->getTypes() as $innerType) {
                $parts[] = self::typeName($innerType);
            }
            sort($parts);

            return implode('&', $parts);
        }

        self::fail('Unsupported reflection type.');
    }
}
