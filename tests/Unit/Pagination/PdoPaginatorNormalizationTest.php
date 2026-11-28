<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Pagination;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdoStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdoPaginatorNormalizationTest extends TestCase
{
    #[DataProvider('pageInputs')]
    public function testPageNormalization(int|string|null $input, int $expected): void
    {
        $result = (new PdoPaginator())->paginate(
            $this->pdo(100),
            $this->descriptor(),
            new PageRequest($input, 20),
            $this->config(defaultPerPage: 20, minPerPage: 1, maxPerPage: 50),
            static fn(array $row): array => $row,
        );

        self::assertSame($expected, $result->page);
    }

    /** @return iterable<array{int|string|null, int}> */
    public static function pageInputs(): iterable
    {
        yield [null, 1];
        yield ['', 1];
        yield [' ', 1];
        yield ['abc', 1];
        yield ['1.2', 1];
        yield ['1e2', 1];
        yield ['+', 1];
        yield [0, 1];
        yield [-1, 1];
        yield ['-1', 1];
        yield [1, 1];
        yield ['+1', 1];
        yield ['0002', 2];
        yield [3, 3];
        yield [str_repeat('9', 30), 1];
        yield ['-' . str_repeat('9', 30), 1];
    }

    #[DataProvider('maxPageInputs')]
    public function testPhpIntMaxPageIsAcceptedWhenTotalPagesPermit(int|string $input): void
    {
        $result = (new PdoPaginator())->paginate(
            $this->pdo(PHP_INT_MAX),
            $this->descriptor(),
            new PageRequest($input, 1),
            $this->config(defaultPerPage: 1, minPerPage: 1, maxPerPage: 50),
            static fn(array $row): array => $row,
        );

        self::assertSame(PHP_INT_MAX, $result->page);
        self::assertSame(1, $result->perPage);
        self::assertSame(PHP_INT_MAX, $result->totalPages);
    }

    /** @return iterable<array{int|string}> */
    public static function maxPageInputs(): iterable
    {
        yield [PHP_INT_MAX];
        yield [(string) PHP_INT_MAX];
    }

    #[DataProvider('perPageInputs')]
    public function testPerPageNormalization(int|string|null $input, int $expected): void
    {
        $result = (new PdoPaginator())->paginate(
            $this->pdo(100),
            $this->descriptor(),
            new PageRequest(1, $input),
            $this->config(defaultPerPage: 20, minPerPage: 2, maxPerPage: 50),
            static fn(array $row): array => $row,
        );

        self::assertSame($expected, $result->perPage);
    }

    /** @return iterable<array{int|string|null, int}> */
    public static function perPageInputs(): iterable
    {
        yield [null, 20];
        yield ['', 20];
        yield ['bad', 20];
        yield [str_repeat('9', 30), 20];
        yield ['-' . str_repeat('9', 30), 20];
        yield [-1, 2];
        yield [0, 2];
        yield [1, 2];
        yield [2, 2];
        yield [25, 25];
        yield [50, 50];
        yield [51, 50];
        yield [PHP_INT_MAX, 50];
        yield [(string) PHP_INT_MAX, 50];
        yield ['0005', 5];
    }

    #[DataProvider('sortInputs')]
    public function testSortNormalization(?string $sort, ?string $direction, string $expectedSort, string $expectedDirection): void
    {
        $result = (new PdoPaginator())->paginate(
            $this->pdo(100),
            $this->descriptor(),
            new PageRequest(1, 20, $sort, $direction),
            $this->config(defaultPerPage: 20, minPerPage: 1, maxPerPage: 50),
            static fn(array $row): array => $row,
        );

        self::assertSame($expectedSort, $result->sortBy);
        self::assertSame($expectedDirection, $result->sortDirection->value);
    }

    /** @return iterable<array{?string, ?string, string, string}> */
    public static function sortInputs(): iterable
    {
        yield [null, null, 'created_at', 'DESC'];
        yield ['', '', 'created_at', 'DESC'];
        yield [' bad ', 'bad', 'created_at', 'DESC'];
        yield ['ID', 'asc', 'created_at', 'ASC'];
        yield [' id ', 'dEsC', 'id', 'DESC'];
        yield ["id\n", null, 'id', 'DESC'];
    }

    private function descriptor(): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor(
            'SELECT COUNT(*) AS total_count',
            [],
            'SELECT COUNT(*) AS filtered_count',
            [],
            'SELECT id FROM t',
            [],
        );
    }

    private function config(int $defaultPerPage, int $minPerPage, int $maxPerPage): PaginationConfig
    {
        return new PaginationConfig(
            new SortWhitelist(['id' => 'id', 'created_at' => 'created_at']),
            'created_at',
            SortDirectionEnum::Desc,
            'id',
            SortDirectionEnum::Asc,
            $defaultPerPage,
            $minPerPage,
            $maxPerPage,
        );
    }

    private function pdo(int $filtered): ScriptedPdo
    {
        return new ScriptedPdo([
            ScriptedPdoStatement::count($filtered),
            ScriptedPdoStatement::count($filtered),
            ScriptedPdoStatement::data([['id' => 1]]),
        ]);
    }
}
