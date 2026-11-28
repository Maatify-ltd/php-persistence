<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Config\StaticSortPlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\ValueObject\ResolvedDynamicSort;
use PDO;
use PDOStatement;
use Throwable;

/** @internal Executes the common page SQL without taking transaction ownership. */
final readonly class PdoPaginationExecution
{
    private const PUBLIC_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const INTEGER_INPUT_PATTERN = '/^[+-]?[0-9]+$/';
    public const LIMIT_PARAMETER = '__pagination_limit';
    public const OFFSET_PARAMETER = '__pagination_offset';

    /**
     * Normalizes the request against the configuration, reads both counts, and
     * maps rows from the sorted, bounded data query. The descriptor supplies
     * trusted SQL and filter parameters. Zero filtered rows skip the data query
     * and return an empty first page. A page above the last page resets to one.
     *
     * Leaves the caller's transaction and isolation level intact. PDO and
     * mapper exceptions propagate unchanged. C1 supplies a resource tracker
     * for explicit cursor release before transaction settlement. The raw path
     * drains results on success and attempts cursor cleanup on a result-reading
     * or mapper failure, preserving the primary throwable.
     *
     * @throws PaginationExecutionException When a statement fails without throwing
     *                                      or a count, row, or mapped item is invalid.
     * @throws InvalidPaginationQueryException When a static plan excludes the resolved sort key.
     *
     * @template TItem of array<array-key, mixed>|object
     *
     * @param callable(array<string, mixed>): TItem $mapper
     *
     * @return PageResultDTO<TItem>
     */
    public function paginate(
        PDO $pdo,
        PdoPaginationQueryDescriptor $query,
        PageRequest $request,
        PaginationConfig $config,
        callable $mapper,
        ?SnapshotReadResources $resources = null,
        ?StaticSortPlan $staticSort = null,
        ?ResolvedDynamicSort $dynamicSort = null,
    ): PageResultDTO {
        $page = $this->normalizePage($request->page);
        $perPage = $this->normalizePerPage($request->perPage, $config);
        $sortBy = $dynamicSort?->primaryKey() ?? $this->resolveSortBy($request->sortBy, $config);
        $sortDirection = $dynamicSort?->primaryDirection() ?? $this->resolveSortDirection($request->sortDirection, $config);
        $trailingTerms = $staticSort?->trailingTermsFor($sortBy);
        /** @var list<TItem> $data */
        $data = [];
        $total = $this->executeCount($pdo, $query->totalSql, $query->totalParams, $resources);
        $filtered = $this->executeCount($pdo, $query->filteredCountSql, $query->filteredCountParams, $resources);
        $totalPages = $filtered === 0 ? 0 : intdiv($filtered - 1, $perPage) + 1;

        if ($filtered === 0) {
            return new PageResultDTO($data, 1, $perPage, $total, $filtered, 0, false, false, $sortBy, $sortDirection);
        }

        if ($page > $totalPages) {
            $page = 1;
        }

        $offset = ($page - 1) * $perPage;
        if ($dynamicSort !== null) {
            $sql = $this->buildDynamicDataSql($query->dataSql, $dynamicSort);
        } else {
            $primary = $config->sortWhitelist->quotedIdentifierFor($sortBy);
            $sql = $trailingTerms === null
                ? $this->buildDataSql($query->dataSql, $primary, $sortDirection, $config->sortWhitelist->quotedIdentifierFor($config->tieBreakerSortBy), $config->tieBreakerDirection)
                : $this->buildStaticDataSql($query->dataSql, $primary, $sortDirection, $trailingTerms);
        }
        $statement = $this->prepareStatement($pdo, $sql);
        $resources?->track($statement);
        $this->bindParameters($statement, $query->dataParams);
        $this->bindValue($statement, self::LIMIT_PARAMETER, $perPage);
        $this->bindValue($statement, self::OFFSET_PARAMETER, $offset);
        if (! $statement->execute()) {
            throw new PaginationExecutionException('Failed to execute pagination data query.');
        }

        try {
            while (true) {
                $row = $this->fetchAssociativeOrEof($statement);
                if ($row === false) {
                    break;
                }
                $this->assertAssociativeDataRow($row);
                $item = $mapper($row);
                $this->assertMappedItem($item);
                $data[] = $item;
            }
        } catch (Throwable $failure) {
            // The exception trace may keep the statement alive after this call exits.
            if ($resources === null) {
                try {
                    $statement->closeCursor();
                } catch (Throwable) {
                    // Preserve the failure that stopped pagination.
                }
            }

            throw $failure;
        }

        $resources?->release();

        return new PageResultDTO($data, $page, $perPage, $total, $filtered, $totalPages, $page < $totalPages, $page > 1 && $totalPages > 0, $sortBy, $sortDirection);
    }

    /**
     * Defaults to 1 for absent, invalid or non-positive requested pages.
     */
    public function normalizePage(int|string|null $value): int
    {
        $parsed = $this->parseIntegerInput($value);

        return $parsed === null || $parsed < 1 ? 1 : $parsed;
    }

    /**
     * Uses the configured default for absent or invalid input. Numeric text
     * outside the PHP integer range is invalid. A valid integer outside the
     * configured page-size bounds is clamped to the nearer bound.
     */
    public function normalizePerPage(int|string|null $value, PaginationConfig $config): int
    {
        $parsed = $this->parseIntegerInput($value);
        if ($parsed === null) {
            return $config->defaultPerPage;
        }
        if ($parsed < $config->minPerPage) {
            return $config->minPerPage;
        }
        if ($parsed > $config->maxPerPage) {
            return $config->maxPerPage;
        }

        return $parsed;
    }

    /**
     * Trims decimal text and accepts an optional sign. Returns null for
     * unparseable text or magnitude beyond the signed PHP integer range.
     */
    private function parseIntegerInput(int|string|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (! preg_match(self::INTEGER_INPUT_PATTERN, $value)) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-');
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        $limit = $negative ? (string) PHP_INT_MIN : (string) PHP_INT_MAX;
        $limitMagnitude = ltrim($limit, '-');
        if (strlen($digits) > strlen($limitMagnitude) || (strlen($digits) === strlen($limitMagnitude) && strcmp($digits, $limitMagnitude) > 0)) {
            return null;
        }

        return (int) (($negative ? '-' : '') . $digits);
    }

    /**
     * Resolves a trimmed request key to an allowlisted key or the configured
     * default. The caller separately obtains its quoted SQL identifier.
     */
    public function resolveSortBy(?string $sortBy, PaginationConfig $config): string
    {
        if ($sortBy === null) {
            return $config->defaultSortBy;
        }
        $sortBy = trim($sortBy);
        if ($sortBy === '' || ! preg_match(self::PUBLIC_KEY_PATTERN, $sortBy) || ! $config->sortWhitelist->contains($sortBy)) {
            return $config->defaultSortBy;
        }

        return $sortBy;
    }

    /**
     * Accepts ASC or DESC after trim and case normalization. Other values
     * use the configured direction.
     */
    public function resolveSortDirection(?string $sortDirection, PaginationConfig $config): SortDirectionEnum
    {
        if ($sortDirection === null) {
            return $config->defaultSortDirection;
        }

        return match (strtoupper(trim($sortDirection))) {
            'ASC' => SortDirectionEnum::Asc,
            'DESC' => SortDirectionEnum::Desc,
            default => $config->defaultSortDirection,
        };
    }

    /**
     * Reads exactly one row and one column, then validates the count. With a
     * tracker, C1 owns cursor release. On a raw read failure, attempts release
     * without replacing the original throwable.
     *
     * @param array<string, string|int|bool|null> $params
     */
    private function executeCount(PDO $pdo, string $sql, array $params, ?SnapshotReadResources $resources): int
    {
        $statement = $this->prepareStatement($pdo, $sql);
        $resources?->track($statement);
        $this->bindParameters($statement, $params);
        if (! $statement->execute()) {
            throw new PaginationExecutionException('Failed to execute pagination count query.');
        }

        try {
            if ($statement->columnCount() !== 1) {
                throw new PaginationExecutionException('Pagination count query must return exactly one column.');
            }
            $row = $this->fetchAssociativeOrEof($statement);
            if ($row === false || count($row) !== 1) {
                throw new PaginationExecutionException('Pagination count query must return exactly one row and one column.');
            }
            if ($this->fetchAssociativeOrEof($statement) !== false) {
                throw new PaginationExecutionException('Pagination count query returned more than one row.');
            }
        } catch (Throwable $failure) {
            // A retained exception trace can keep the statement and its unread rows alive.
            if ($resources === null) {
                try {
                    $statement->closeCursor();
                } catch (Throwable) {
                    // Preserve the count failure.
                }
            }

            throw $failure;
        }

        $resources?->release();

        return $this->normalizeCountValue(reset($row));
    }

    private function prepareStatement(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new PaginationExecutionException('Failed to prepare pagination query.');
        }

        return $statement;
    }

    /** @param array<string, string|int|bool|null> $params */
    private function bindParameters(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $this->bindValue($statement, $key, $value);
        }
    }

    /**
     * Binds a bare placeholder name using the appropriate PDO type. A driver
     * failure reported as false raises PaginationExecutionException.
     */
    private function bindValue(PDOStatement $statement, string $key, string|int|bool|null $value): void
    {
        if (! $statement->bindValue(':' . $key, $value, $this->pdoParameterType($value))) {
            throw new PaginationExecutionException('Failed to bind pagination parameter.');
        }
    }

    private function pdoParameterType(string|int|bool|null $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * Recognizes false as EOF only on successful SQLSTATE. Rejects other
     * failures, non-array rows and non-string associative keys.
     *
     * @return array<string, mixed>|false
     */
    private function fetchAssociativeOrEof(PDOStatement $statement): array|false
    {
        /** @var mixed $row The PDO stub is narrower than what a driver or test double may return. */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            if ($statement->errorCode() === '00000') {
                return false;
            }
            throw new PaginationExecutionException('Failed to fetch pagination row.');
        }
        if (! is_array($row)) {
            throw new PaginationExecutionException('Pagination fetch must return an associative array.');
        }
        $associativeRow = [];
        foreach ($row as $key => $value) {
            if (! is_string($key)) {
                throw new PaginationExecutionException('Pagination row keys must be strings.');
            }
            $associativeRow[$key] = $value;
        }

        return $associativeRow;
    }

    /** @param array<mixed, mixed> $row */
    private function assertAssociativeDataRow(array $row): void
    {
        if ($row === []) {
            throw new PaginationExecutionException('Pagination data row must contain at least one column.');
        }
    }

    private function assertMappedItem(mixed $item): void
    {
        if (! is_array($item) && ! is_object($item)) {
            throw new PaginationExecutionException('Pagination mapper must return array or object.');
        }
    }

    /**
     * Accepts a non-negative integer or an untrimmed string of ASCII decimal
     * digits whose value is in 0..PHP_INT_MAX. Leading zeroes are allowed.
     *
     * @throws PaginationExecutionException When the count is invalid or out of range.
     */
    public function normalizeCountValue(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new PaginationExecutionException('Pagination count cannot be negative.');
            }

            return $value;
        }
        if (! is_string($value) || ! preg_match('/\A[0-9]+\z/', $value)) {
            throw new PaginationExecutionException('Invalid pagination count value.');
        }
        $digits = ltrim($value, '0');
        if ($digits === '') {
            $digits = '0';
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new PaginationExecutionException('Pagination count exceeds PHP integer range.');
        }

        return (int) $digits;
    }

    /**
     * Appends the sort and bound page window to trusted base SQL. Sort
     * identifiers must already be quoted. Equal quoted identifiers omit the
     * tie-breaker term.
     */
    public function buildDataSql(string $dataSql, string $quotedPrimary, SortDirectionEnum $direction, string $quotedTieBreaker, SortDirectionEnum $tieBreakerDirection): string
    {
        $order = $quotedPrimary . ' ' . $direction->value;
        if ($quotedPrimary !== $quotedTieBreaker) {
            $order .= ', ' . $quotedTieBreaker . ' ' . $tieBreakerDirection->value;
        }

        return $this->boundedDataSql($dataSql, $order);
    }

    /** @param non-empty-list<array{identifier: non-empty-string, direction: SortDirectionEnum|null}> $trailingTerms */
    private function buildStaticDataSql(string $dataSql, string $quotedPrimary, SortDirectionEnum $direction, array $trailingTerms): string
    {
        $order = $quotedPrimary . ' ' . $direction->value;
        foreach ($trailingTerms as $term) {
            $order .= ', ' . $term['identifier'] . ' ' . ($term['direction'] ?? $direction)->value;
        }

        return $this->boundedDataSql($dataSql, $order);
    }

    private function boundedDataSql(string $dataSql, string $order): string
    {
        return rtrim($dataSql) . "\nORDER BY " . $order . "\nLIMIT :" . self::LIMIT_PARAMETER . "\nOFFSET :" . self::OFFSET_PARAMETER;
    }

    /** Formats the trusted terms in the same order used for result metadata. */
    private function buildDynamicDataSql(string $dataSql, ResolvedDynamicSort $sort): string
    {
        $terms = array_map(
            static fn(array $term): string => $term['identifier'] . ' ' . $term['direction']->value,
            $sort->effectiveTerms(),
        );

        return $this->boundedDataSql($dataSql, implode(', ', $terms));
    }
}
