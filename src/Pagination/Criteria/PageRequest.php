<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

/**
 * The raw, untrusted page/per-page/sort request from the caller.
 *
 * This type performs no validation or normalization. The caller checks the
 * shape of incoming request values against the constructor parameter types.
 * {@see \Maatify\PhpPersistence\Pagination\Repository\PdoPaginator::paginate()}
 * normalizes every property against a {@see \Maatify\PhpPersistence\Pagination\Config\PaginationConfig}.
 */
final readonly class PageRequest
{
    public function __construct(
        public int|string|null $page = null,
        public int|string|null $perPage = null,
        public ?string $sortBy = null,
        public ?string $sortDirection = null,
    ) {}
}
