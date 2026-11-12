<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Config;

use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;

/**
 * Pagination defaults, bounds, and the allowed sort keys for one query shape.
 *
 * The constructor validates its own invariants and throws
 * {@see InvalidPaginationConfigurationException} rather than deferring to
 * {@see \Maatify\PhpPersistence\Pagination\Repository\PdoPaginator}:
 * - minPerPage must be at least 1, maxPerPage must be at least minPerPage, and
 *   defaultPerPage must fall inside [minPerPage, maxPerPage];
 * - defaultSortBy and tieBreakerSortBy must each be a valid public key
 *   (`^[A-Za-z_][A-Za-z0-9_]*$`) present in sortWhitelist.
 *
 * One instance is reused across requests for the same query shape; it carries
 * no per-request state.
 */
final readonly class PaginationConfig
{
    private const PUBLIC_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        public SortWhitelist $sortWhitelist,
        public string $defaultSortBy,
        public SortDirectionEnum $defaultSortDirection,
        public string $tieBreakerSortBy,
        public SortDirectionEnum $tieBreakerDirection,
        public int $defaultPerPage = 20,
        public int $minPerPage = 1,
        public int $maxPerPage = 200,
    ) {
        if ($this->minPerPage < 1 || $this->maxPerPage < $this->minPerPage || $this->defaultPerPage < $this->minPerPage || $this->defaultPerPage > $this->maxPerPage) {
            throw new InvalidPaginationConfigurationException('Invalid pagination per-page configuration.');
        }
        if (! preg_match(self::PUBLIC_KEY_PATTERN, $this->defaultSortBy) || ! $this->sortWhitelist->contains($this->defaultSortBy)) {
            throw new InvalidPaginationConfigurationException('Invalid pagination default sort key.');
        }
        if (! preg_match(self::PUBLIC_KEY_PATTERN, $this->tieBreakerSortBy) || ! $this->sortWhitelist->contains($this->tieBreakerSortBy)) {
            throw new InvalidPaginationConfigurationException('Invalid pagination tie-breaker sort key.');
        }
    }
}
