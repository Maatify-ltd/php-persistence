<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotGrantRegistration;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;

/** @internal Reads and closes all page statements before the Host's mapper runs. */
final readonly class HostSnapshotPageReader
{
    public function __construct(private HostSnapshotConnection $connection) {}

    /**
     * Uses the registered count and data statements to produce one bounded page.
     * Sets the mapping-phase flag after the data cursor closes, before validating
     * row keys or invoking the Host's mapper. An empty filtered result skips mapping.
     *
     * @return PageResultDTO<array<array-key, mixed>|object>
     */
    public function read(HostSnapshotGrantRegistration $registered, bool &$mapping): PageResultDTO
    {
        $shape = new PdoPaginationExecution();
        $config = $registered->plan->config;
        $query = $registered->query;
        $request = $registered->request;
        $page = $shape->normalizePage($request->page);
        $perPage = $shape->normalizePerPage($request->perPage, $config);
        $sortBy = $shape->resolveSortBy($request->sortBy, $config);
        $direction = $shape->resolveSortDirection($request->sortDirection, $config);
        $total = $this->count($query->totalSql, $query->totalParams, $shape);
        $filtered = $this->count($query->filteredCountSql, $query->filteredCountParams, $shape);
        $pages = $filtered === 0 ? 0 : intdiv($filtered - 1, $perPage) + 1;

        if ($filtered === 0) {
            return new PageResultDTO([], 1, $perPage, $total, 0, 0, false, false, $sortBy, $direction);
        }

        $page = $page > $pages ? 1 : $page;
        $sql = $shape->buildDataSql(
            $query->dataSql,
            $config->sortWhitelist->quotedIdentifierFor($sortBy),
            $direction,
            $config->sortWhitelist->quotedIdentifierFor($config->tieBreakerSortBy),
            $config->tieBreakerDirection,
        );
        $rows = $this->connection->rows($sql, [
            ...$query->dataParams,
            PdoPaginationExecution::LIMIT_PARAMETER => $perPage,
            PdoPaginationExecution::OFFSET_PARAMETER => ($page - 1) * $perPage,
        ], $perPage);
        $mapping = true;
        $data = [];
        foreach ($rows as $row) {
            if ($row === []) {
                throw new PaginationExecutionException('Pagination data row must contain at least one column.');
            }
            $associativeRow = [];
            foreach ($row as $name => $value) {
                if (! is_string($name)) {
                    throw new PaginationExecutionException('Pagination row keys must be strings.');
                }
                $associativeRow[$name] = $value;
            }
            /** @var mixed $item The supplied closure is still checked at runtime. */
            $item = ($registered->plan->mapper)($associativeRow);
            if (! is_array($item) && ! is_object($item)) {
                throw new PaginationExecutionException('Pagination mapper must return array or object.');
            }
            $data[] = $item;
        }

        return new PageResultDTO($data, $page, $perPage, $total, $filtered, $pages, $page < $pages, $page > 1, $sortBy, $direction);
    }

    /**
     * Accepts a single scalar count from one row and one column. Fetching
     * positional rows keeps duplicate aliases from hiding multiple columns.
     *
     * @param array<string, string|int|bool|null> $bindings
     */
    private function count(string $sql, array $bindings, PdoPaginationExecution $shape): int
    {
        $rows = $this->connection->rows($sql, $bindings, 2, positional: true);
        if (count($rows) !== 1 || count($rows[0]) !== 1) {
            throw new PaginationExecutionException('Pagination count query must return exactly one row and one column.');
        }
        $row = $rows[0];

        return $shape->normalizeCountValue(reset($row));
    }
}
