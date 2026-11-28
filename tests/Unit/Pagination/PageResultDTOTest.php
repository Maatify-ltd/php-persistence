<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Pagination;

use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PageResultDTOTest extends TestCase
{
    public function testValidConstructionMetadataAndSerialization(): void
    {
        $object = new class {
            public bool $toArrayCalled = false;
            public bool $jsonSerializeCalled = false;

            /** @return array<string, bool> */
            public function toArray(): array
            {
                $this->toArrayCalled = true;

                return ['called' => true];
            }

            /** @return array<string, bool> */
            public function jsonSerialize(): array
            {
                $this->jsonSerializeCalled = true;

                return ['called' => true];
            }
        };

        $result = new PageResultDTO([['id' => 1], $object], 1, 20, 30, 22, 2, true, false, 'created_at', SortDirectionEnum::Desc);
        $array = $result->toArray();

        self::assertSame($array, $result->jsonSerialize());
        self::assertArrayNotHasKey('offset', $array['pagination']);
        self::assertSame($object, $array['data'][1]);
        self::assertSame(2, $array['pagination']['total_pages']);
        self::assertFalse($object->toArrayCalled);
        self::assertFalse($object->jsonSerializeCalled);
    }

    public function testRejectsTerminalLineFeedInDirectSortKey(): void
    {
        $this->expectException(PaginationExecutionException::class);
        $this->expectExceptionMessage('Invalid pagination result state.');

        new PageResultDTO([], 1, 20, 0, 0, 0, false, false, "id\n", SortDirectionEnum::Asc);
    }

    public function testPreservesValidDirectSortKeysInSerialization(): void
    {
        foreach (['id', '_id2'] as $key) {
            $result = new PageResultDTO([], 1, 20, 0, 0, 0, false, false, $key, SortDirectionEnum::Asc);
            $array = $result->toArray();

            self::assertSame($key, $array['pagination']['sort_by']);
            self::assertSame($array, $result->jsonSerialize());
        }
    }

    /** @param list<array<array-key, mixed>|object> $data */
    #[DataProvider('validResults')]
    public function testValidResultMatrix(array $data, int $page, int $perPage, int $total, int $filtered, int $totalPages, bool $hasNext, bool $hasPrevious): void
    {
        $result = new PageResultDTO($data, $page, $perPage, $total, $filtered, $totalPages, $hasNext, $hasPrevious, 'id', SortDirectionEnum::Asc);

        self::assertSame($page, $result->page);
        self::assertSame($data, $result->data);
    }

    /** @return iterable<string, array{list<array<array-key, mixed>|object>, int, int, int, int, int, bool, bool}> */
    public static function validResults(): iterable
    {
        yield 'arrays' => [[['id' => 1]], 1, 20, 1, 1, 1, false, false];
        yield 'objects' => [[new \stdClass()], 1, 20, 1, 1, 1, false, false];
        yield 'empty positive filtered' => [[], 2, 20, 50, 50, 3, true, true];
        yield 'zero result' => [[], 1, 20, 0, 0, 0, false, false];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidResults')]
    public function testInvariantRejection(array $arguments): void
    {
        $this->expectException(PaginationExecutionException::class);

        $this->newPageResultDTO($arguments);
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function invalidResults(): iterable
    {
        yield 'associative outer data' => [[['x' => []], 1, 20, 0, 0, 0, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'scalar item' => [[[1], 1, 20, 1, 1, 1, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'page below one' => [[[], 0, 20, 0, 0, 0, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'per page below one' => [[[], 1, 0, 0, 0, 0, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'negative total' => [[[], 1, 20, -1, 0, 0, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'negative filtered' => [[[], 1, 20, 0, -1, 0, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'negative pages' => [[[], 1, 20, 0, 0, -1, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'data exceeds per page' => [[[[], []], 1, 1, 2, 2, 2, true, false, 'id', SortDirectionEnum::Asc]];
        yield 'bad sort key' => [[[], 1, 20, 0, 0, 0, false, false, 'bad-key', SortDirectionEnum::Asc]];
        yield 'bad total pages' => [[[], 1, 20, 20, 20, 2, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'zero filtered non-empty data' => [[[['id' => 1]], 1, 20, 0, 0, 0, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'zero filtered page not one' => [[[], 2, 20, 0, 0, 0, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'zero filtered has next' => [[[], 1, 20, 0, 0, 0, true, false, 'id', SortDirectionEnum::Asc]];
        yield 'bad has next' => [[[], 1, 20, 50, 50, 3, false, false, 'id', SortDirectionEnum::Asc]];
        yield 'bad has previous' => [[[], 1, 20, 50, 50, 3, true, true, 'id', SortDirectionEnum::Asc]];
    }

    /**
     * @param list<mixed> $arguments
     *
     * @return PageResultDTO<array<array-key, mixed>|object>
     */
    private function newPageResultDTO(array $arguments): PageResultDTO
    {
        $reflection = new ReflectionClass(PageResultDTO::class);

        /** @var PageResultDTO<array<array-key, mixed>|object> $result */
        $result = $reflection->newInstanceArgs($arguments);

        return $result;
    }
}
