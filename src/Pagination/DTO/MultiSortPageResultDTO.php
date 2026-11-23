<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use JsonSerializable;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\ValueObject\ResolvedDynamicSort;

/**
 * A dynamic Offset page and its selected and effective sort terms.
 *
 * Sort metadata uses public labels and the same order as the data SQL. The
 * appended Host tie has a null label. Array and JSON output extend only the
 * pagination metadata of this result with the two ordered sort lists.
 *
 * @template T of array<array-key, mixed>|object
 * @phpstan-type SerializedSelection array{key: string, direction: 'ASC'|'DESC'}
 * @phpstan-type SerializedEffectiveTerm array{key: string|null, direction: 'ASC'|'DESC'}
 */
final readonly class MultiSortPageResultDTO implements JsonSerializable
{
    /**
     * @param PageResultDTO<T> $page
     * @param list<array{key: string, direction: SortDirectionEnum}> $selectedSorts
     * @param non-empty-list<array{key: string|null, direction: SortDirectionEnum}> $effectiveSorts
     */
    private function __construct(
        public PageResultDTO $page,
        public array $selectedSorts,
        public array $effectiveSorts,
    ) {}

    /**
     * Wraps a completed page from the same resolved order used to issue SQL.
     * The internal resolver makes selected order a prefix of effective order
     * and places the single tie last. The page must agree with its first term.
     *
     * @internal
     * @template TItem of array<array-key, mixed>|object
     * @param PageResultDTO<TItem> $page
     * @return self<TItem>
     * @throws PaginationExecutionException If the page's primary sort metadata disagrees with the order.
     */
    public static function fromResolved(PageResultDTO $page, ResolvedDynamicSort $sort): self
    {
        if ($page->sortBy !== $sort->primaryKey() || $page->sortDirection !== $sort->primaryDirection()) {
            throw new PaginationExecutionException('Page sort metadata does not match the resolved dynamic pagination order.');
        }

        $selected = array_map(
            static fn(array $term): array => ['key' => $term['key'], 'direction' => $term['direction']],
            $sort->selectedTerms(),
        );
        $effective = array_map(
            static fn(array $term): array => ['key' => $term['key'], 'direction' => $term['direction']],
            $sort->effectiveTerms(),
        );

        return new self($page, $selected, $effective);
    }

    /**
     * @return array{data: list<T>, pagination: array{page: int, per_page: int, total: int, filtered: int, total_pages: int, has_next: bool, has_previous: bool, sort_by: string, sort_direction: 'ASC'|'DESC', selected_sorts: list<SerializedSelection>, effective_sorts: non-empty-list<SerializedEffectiveTerm>}}
     */
    public function toArray(): array
    {
        $result = $this->page->toArray();
        $result['pagination']['selected_sorts'] = array_map(self::serializeTerm(...), $this->selectedSorts);
        $result['pagination']['effective_sorts'] = array_map(self::serializeTerm(...), $this->effectiveSorts);

        return $result;
    }

    /**
     * @return array{data: list<T>, pagination: array{page: int, per_page: int, total: int, filtered: int, total_pages: int, has_next: bool, has_previous: bool, sort_by: string, sort_direction: 'ASC'|'DESC', selected_sorts: list<SerializedSelection>, effective_sorts: non-empty-list<SerializedEffectiveTerm>}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Preserves result invariants by requiring the paginator's controlled factory.
     *
     * @internal
     * @param array<mixed> $data
     * @throws PaginationExecutionException Always.
     */
    public function __unserialize(array $data): void
    {
        throw new PaginationExecutionException('A dynamic pagination result must come from a resolved page.');
    }

    /**
     * @template TKey of string|null
     * @param array{key: TKey, direction: SortDirectionEnum} $term
     * @return array{key: TKey, direction: 'ASC'|'DESC'}
     */
    private static function serializeTerm(array $term): array
    {
        return ['key' => $term['key'], 'direction' => $term['direction']->value];
    }
}
