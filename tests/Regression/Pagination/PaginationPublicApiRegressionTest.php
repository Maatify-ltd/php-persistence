<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression\Pagination;

use JsonSerializable;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Reflects over the approved public Pagination contract, including DEC-036's
 * sort direction cases. Golden fixtures for this inventory were captured from legacy `main` (1f6a7aa) before this
 * capability's first implementation, per the DEC-007 provenance gate.
 */
final class PaginationPublicApiRegressionTest extends TestCase
{
    public function testApprovedNamespacesTypesAndModifiers(): void
    {
        $expectedNamespaceByClass = [
            PageRequest::class => 'Maatify\PhpPersistence\Pagination\Criteria',
            PdoPaginationQueryDescriptor::class => 'Maatify\PhpPersistence\Pagination\Criteria',
            PaginationConfig::class => 'Maatify\PhpPersistence\Pagination\Config',
            SortWhitelist::class => 'Maatify\PhpPersistence\Pagination\ValueObject',
            PdoPaginator::class => 'Maatify\PhpPersistence\Pagination\Repository',
            PageResultDTO::class => 'Maatify\PhpPersistence\Pagination\DTO',
        ];

        foreach ($expectedNamespaceByClass as $className => $expectedNamespace) {
            $class = new ReflectionClass($className);
            self::assertSame($expectedNamespace, $class->getNamespaceName(), $className);
            self::assertTrue($class->isFinal(), $className);
            self::assertTrue($class->isReadOnly(), $className);
        }

        self::assertSame('Maatify\PhpPersistence\Pagination\Enum', (new ReflectionClass(SortDirectionEnum::class))->getNamespaceName());
        self::assertSame(['Asc' => 'ASC', 'Desc' => 'DESC'], array_column(SortDirectionEnum::cases(), 'value', 'name'));
        self::assertSame(['cases', 'from', 'tryFrom'], array_map(static fn(\ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(SortDirectionEnum::class))->getMethods()));
    }

    /** Captures the DEC-036 limits of the published sort direction aliases. */
    public function testPublishedSortExpressionsRemainCompatible(): void
    {
        $reflection = new ReflectionEnum(SortDirectionEnum::class);

        self::assertSame(SortDirectionEnum::Asc, SortDirectionEnum::ASC);
        self::assertSame(SortDirectionEnum::Desc, SortDirectionEnum::DESC);
        self::assertSame(SortDirectionEnum::Asc, SortDirectionEnum::from('ASC'));
        self::assertSame(SortDirectionEnum::Desc, SortDirectionEnum::tryFrom('DESC'));
        self::assertFalse($reflection->hasCase('ASC'));
        self::assertFalse($reflection->hasCase('DESC'));
        self::assertStringContainsString(':Asc"', serialize(SortDirectionEnum::ASC));
        self::assertStringContainsString(':Desc"', serialize(SortDirectionEnum::DESC));
        self::assertSame(SortDirectionEnum::Asc, unserialize(serialize(SortDirectionEnum::ASC)));
    }

    public function testConstructorContractsAndPromotedProperties(): void
    {
        self::assertConstructor(PageRequest::class, [
            ['page', 'int|string', true, true, null, true],
            ['perPage', 'int|string', true, true, null, true],
            ['sortBy', 'string', true, true, null, true],
            ['sortDirection', 'string', true, true, null, true],
        ]);
        self::assertConstructor(PaginationConfig::class, [
            ['sortWhitelist', SortWhitelist::class, false, false, null, true],
            ['defaultSortBy', 'string', false, false, null, true],
            ['defaultSortDirection', SortDirectionEnum::class, false, false, null, true],
            ['tieBreakerSortBy', 'string', false, false, null, true],
            ['tieBreakerDirection', SortDirectionEnum::class, false, false, null, true],
            ['defaultPerPage', 'int', false, true, 20, true],
            ['minPerPage', 'int', false, true, 1, true],
            ['maxPerPage', 'int', false, true, 200, true],
        ]);
        self::assertConstructor(PdoPaginationQueryDescriptor::class, [
            ['totalSql', 'string', false, false, null, true],
            ['totalParams', 'array', false, false, null, true],
            ['filteredCountSql', 'string', false, false, null, true],
            ['filteredCountParams', 'array', false, false, null, true],
            ['dataSql', 'string', false, false, null, true],
            ['dataParams', 'array', false, false, null, true],
        ]);
        self::assertConstructor(PageResultDTO::class, [
            ['data', 'array', false, false, null, true],
            ['page', 'int', false, false, null, true],
            ['perPage', 'int', false, false, null, true],
            ['total', 'int', false, false, null, true],
            ['filtered', 'int', false, false, null, true],
            ['totalPages', 'int', false, false, null, true],
            ['hasNext', 'bool', false, false, null, true],
            ['hasPrevious', 'bool', false, false, null, true],
            ['sortBy', 'string', false, false, null, true],
            ['sortDirection', SortDirectionEnum::class, false, false, null, true],
        ]);
        self::assertConstructor(SortWhitelist::class, [['sorts', 'array', false, false, null, false]]);
        self::assertNull((new ReflectionClass(PdoPaginator::class))->getConstructor());
    }

    public function testPublicMethodsAndResultEnvelope(): void
    {
        self::assertMethod(PdoPaginator::class, 'paginate', ['pdo', 'query', 'request', 'config', 'mapper'], PageResultDTO::class);
        self::assertMethod(SortWhitelist::class, 'contains', ['key'], 'bool');
        self::assertMethod(SortWhitelist::class, 'quotedIdentifierFor', ['key'], 'string');
        self::assertMethod(PageResultDTO::class, 'toArray', [], 'array');
        self::assertMethod(PageResultDTO::class, 'jsonSerialize', [], 'array');
        self::assertContains(JsonSerializable::class, class_implements(PageResultDTO::class));

        $result = new PageResultDTO([], 1, 20, 0, 0, 0, false, false, 'id', SortDirectionEnum::Asc);
        self::assertSame(['data', 'pagination'], array_keys($result->toArray()));
        self::assertSame(['page', 'per_page', 'total', 'filtered', 'total_pages', 'has_next', 'has_previous', 'sort_by', 'sort_direction'], array_keys($result->toArray()['pagination']));
        self::assertArrayNotHasKey('offset', $result->toArray()['pagination']);
    }

    public function testNoLegacyPageResultNameIsCarried(): void
    {
        self::assertFalse(class_exists('Maatify\\PhpPersistence\\Pagination\\PageResult'));
        self::assertFalse(class_exists('Maatify\\PhpPersistence\\Pagination\\DTO\\PageResult'));
        self::assertFalse(interface_exists('Maatify\\PhpPersistence\\Pagination\\Exception\\PaginationException'));
    }

    /**
     * @param class-string<object> $className
     * @param list<array{string, string, bool, bool, mixed, bool}> $expected
     */
    private static function assertConstructor(string $className, array $expected): void
    {
        $class = new ReflectionClass($className);
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        self::assertParameters($constructor->getParameters(), $expected);
    }

    /**
     * @param class-string<object> $className
     * @param list<string> $parameterNames
     */
    private static function assertMethod(string $className, string $methodName, array $parameterNames, string $returnType): void
    {
        $method = (new ReflectionClass($className))->getMethod($methodName);
        self::assertTrue($method->isPublic());
        self::assertSame($parameterNames, array_map(static fn(ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters()));
        self::assertSame($returnType, self::typeName($method->getReturnType()));
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param list<array{string, string, bool, bool, mixed, bool}> $expected
     */
    private static function assertParameters(array $parameters, array $expected): void
    {
        self::assertCount(count($expected), $parameters);
        foreach ($expected as $index => [$name, $type, $nullable, $hasDefault, $default, $promoted]) {
            $parameter = $parameters[$index];
            self::assertSame($name, $parameter->getName());
            self::assertSame($type, self::typeName($parameter->getType()));
            self::assertSame($nullable, $parameter->allowsNull());
            self::assertSame($hasDefault, $parameter->isDefaultValueAvailable());
            self::assertSame($promoted, $parameter->isPromoted());
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
