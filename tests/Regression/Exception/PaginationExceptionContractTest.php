<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression\Exception;

use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\MaatifyException;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaginationExceptionContractTest extends TestCase
{
    /** @param class-string<\Throwable> $className */
    #[DataProvider('exceptionClasses')]
    public function testExceptionContract(string $className): void
    {
        $class = new ReflectionClass($className);
        $exception = new $className('pagination');

        self::assertTrue($class->isFinal());
        self::assertInstanceOf(SystemMaatifyException::class, $exception);
        self::assertInstanceOf(PhpPersistenceExceptionInterface::class, $exception);
        self::assertSame(ErrorCodeEnum::MAATIFY_ERROR, $exception->getErrorCode());
    }

    /** @return iterable<string, array{class-string<\Throwable>}> */
    public static function exceptionClasses(): iterable
    {
        yield 'configuration' => [InvalidPaginationConfigurationException::class];
        yield 'query' => [InvalidPaginationQueryException::class];
        yield 'execution' => [PaginationExecutionException::class];
    }

    #[DataProvider('packageDefinedPaginationExceptions')]
    public function testPackageDefinedPaginationExceptionsFollowDecision004(string $className): void
    {
        self::assertTrue(class_exists($className));
        $actual = [
            'suffix' => str_ends_with($className, 'Exception'),
            'hierarchy' => is_subclass_of($className, MaatifyException::class),
            'marker' => is_subclass_of($className, PhpPersistenceExceptionInterface::class),
        ];

        self::assertSame(['suffix' => true, 'hierarchy' => true, 'marker' => true], $actual);
    }

    /** @return iterable<string, array{string}> */
    public static function packageDefinedPaginationExceptions(): iterable
    {
        $files = glob(__DIR__ . '/../../../src/Pagination/Exception/*.php');
        foreach ($files ?: [] as $file) {
            $name = basename($file, '.php');

            yield $name => ['Maatify\\PhpPersistence\\Pagination\\Exception\\' . $name];
        }
    }

    public function testNoPaginationSpecificMarkerOrLegacyPageResultIsCarried(): void
    {
        self::assertFalse(interface_exists('Maatify\\PhpPersistence\\Pagination\\Exception\\PaginationException'));
        self::assertFalse(class_exists('Maatify\\PhpPersistence\\Pagination\\DTO\\PageResult'));
        self::assertFalse(class_exists('Maatify\\PhpPersistence\\Pagination\\PageResult'));
    }

    public function testRepresentativeConfigurationTrigger(): void
    {
        $this->expectException(InvalidPaginationConfigurationException::class);

        $reflection = new ReflectionClass(SortWhitelist::class);
        $reflection->newInstanceArgs([[]]);
    }

    public function testRepresentativeQueryTrigger(): void
    {
        $this->expectException(InvalidPaginationQueryException::class);

        new PdoPaginationQueryDescriptor('', [], 'SELECT COUNT(*) AS filtered_count', [], 'SELECT id FROM t', []);
    }

    public function testRepresentativeExecutionTrigger(): void
    {
        $this->expectException(PaginationExecutionException::class);

        new PageResultDTO([], 0, 20, 0, 0, 0, false, false, 'id', SortDirectionEnum::ASC);
    }
}
