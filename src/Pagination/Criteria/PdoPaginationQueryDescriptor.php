<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;

/**
 * The caller's own SQL and bound parameters for one pagination query shape:
 * a total count, a filtered count, and the unsorted/unlimited data query that
 * {@see \Maatify\PhpPersistence\Pagination\Repository\PdoPaginator} appends
 * `ORDER BY`/`LIMIT`/`OFFSET` to.
 *
 * The constructor validates each SQL string and parameter map and throws
 * {@see InvalidPaginationQueryException}:
 * - no SQL string may be blank, contain a literal `;`, or reference the
 *   `__pagination_` placeholder prefix reserved by `PdoPaginator`;
 * - every parameter key must be a non-prefixed, non-`:`-prefixed identifier
 *   matching `^[A-Za-z_][A-Za-z0-9_]*$`, and every value must be
 *   `string|int|bool|null`.
 *
 * This validation only rejects obviously unsafe shapes (reserved names,
 * statement separators); it does not parse or otherwise understand the SQL.
 */
final readonly class PdoPaginationQueryDescriptor
{
    private const PARAMETER_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const RESERVED_PREFIX = '__pagination_';

    /**
     * @param array<string, string|int|bool|null> $totalParams
     * @param array<string, string|int|bool|null> $filteredCountParams
     * @param array<string, string|int|bool|null> $dataParams
     */
    public function __construct(
        public string $totalSql,
        public array $totalParams,
        public string $filteredCountSql,
        public array $filteredCountParams,
        public string $dataSql,
        public array $dataParams,
    ) {
        $this->validateSql($this->totalSql);
        $this->validateSql($this->filteredCountSql);
        $this->validateSql($this->dataSql);
        $this->validateParams($this->totalParams);
        $this->validateParams($this->filteredCountParams);
        $this->validateParams($this->dataParams);
    }

    private function validateSql(string $sql): void
    {
        if (trim($sql) === '' || str_contains($sql, ';') || str_contains($sql, ':' . self::RESERVED_PREFIX)) {
            throw new InvalidPaginationQueryException('Invalid pagination SQL.');
        }
    }

    /**
     * @param array<mixed, mixed> $params
     */
    private function validateParams(array $params): void
    {
        foreach ($params as $key => $value) {
            if (! is_string($key) || str_starts_with($key, ':') || ! preg_match(self::PARAMETER_KEY_PATTERN, $key) || str_starts_with($key, self::RESERVED_PREFIX)) {
                throw new InvalidPaginationQueryException('Invalid pagination parameter key.');
            }
            if (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                throw new InvalidPaginationQueryException('Invalid pagination parameter value.');
            }
        }
    }
}
