<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use JsonSerializable;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;

/**
 * Immutable mapped page and navigation metadata shared by the array and JSON
 * representations. Counts come from separate reads and can differ under
 * concurrent writes.
 *
 * @template T of array<array-key, mixed>|object
 */
final readonly class PageResultDTO implements JsonSerializable
{
    private const PUBLIC_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Validates a one-based page with a positive size, non-negative counts,
     * and at most one page of mapped items. The page count and navigation
     * flags must agree with the filtered count and page size. A zero filtered
     * count requires empty data, page one, and no navigation.
     *
     * @param list<T> $data Mapped items in page order.
     *
     * @throws PaginationExecutionException When the result is inconsistent.
     */
    public function __construct(
        public array $data,
        public int $page,
        public int $perPage,
        public int $total,
        public int $filtered,
        public int $totalPages,
        public bool $hasNext,
        public bool $hasPrevious,
        public string $sortBy,
        public SortDirectionEnum $sortDirection,
    ) {
        $this->assertValid();
    }

    /**
     * @return array{data: list<T>, pagination: array{page: int, per_page: int, total: int, filtered: int, total_pages: int, has_next: bool, has_previous: bool, sort_by: string, sort_direction: 'ASC'|'DESC'}}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'filtered' => $this->filtered,
                'total_pages' => $this->totalPages,
                'has_next' => $this->hasNext,
                'has_previous' => $this->hasPrevious,
                'sort_by' => $this->sortBy,
                'sort_direction' => $this->sortDirection->value,
            ],
        ];
    }

    /**
     * @return array{data: list<T>, pagination: array{page: int, per_page: int, total: int, filtered: int, total_pages: int, has_next: bool, has_previous: bool, sort_by: string, sort_direction: 'ASC'|'DESC'}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function assertData(array $data): void
    {
        if (! array_is_list($data)) {
            throw new PaginationExecutionException('Pagination data must be a list.');
        }
        foreach ($data as $item) {
            if (! is_array($item) && ! is_object($item)) {
                throw new PaginationExecutionException('Pagination data item must be array or object.');
            }
        }
    }

    private function assertValid(): void
    {
        $this->assertData($this->data);
        if ($this->page < 1 || $this->perPage < 1 || $this->total < 0 || $this->filtered < 0 || $this->totalPages < 0 || count($this->data) > $this->perPage || ! preg_match(self::PUBLIC_KEY_PATTERN, $this->sortBy)) {
            throw new PaginationExecutionException('Invalid pagination result state.');
        }
        $expectedTotalPages = $this->filtered === 0 ? 0 : intdiv($this->filtered - 1, $this->perPage) + 1;
        if ($this->totalPages !== $expectedTotalPages) {
            throw new PaginationExecutionException('Invalid pagination total pages.');
        }
        if ($this->filtered === 0 && ($this->data !== [] || $this->page !== 1 || $this->totalPages !== 0 || $this->hasNext || $this->hasPrevious)) {
            throw new PaginationExecutionException('Invalid empty pagination result state.');
        }
        if ($this->totalPages > 0 && $this->page > $this->totalPages) {
            throw new PaginationExecutionException('Pagination page exceeds total pages.');
        }
        if ($this->hasNext !== ($this->page < $this->totalPages) || $this->hasPrevious !== ($this->page > 1 && $this->totalPages > 0)) {
            throw new PaginationExecutionException('Invalid pagination navigation state.');
        }
    }
}
