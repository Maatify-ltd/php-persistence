<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Config\StaticSortPlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use PDO;

/**
 * Paginates caller-supplied SQL on a caller-owned PDO connection.
 *
 * Request values select bounded page sizes and whitelisted sort identifiers.
 * Total, filtered count, and data are separate reads. The caller controls
 * transaction ownership and any shared snapshot.
 */
final readonly class PdoPaginator
{
    /**
     * Normalizes the request against the configuration, reads both counts, and
     * maps rows from the sorted, bounded data query. The descriptor supplies
     * trusted SQL and filter parameters. Zero filtered rows skip the data query
     * and return an empty first page. A page above the last page resets to one.
     *
     * Leaves the caller's transaction and isolation level intact. PDO and
     * mapper exceptions propagate unchanged.
     *
     * @throws PaginationExecutionException When a statement fails without throwing
     *                                      or a count, row, or mapped item is invalid.
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
    ): PageResultDTO {
        return (new PdoPaginationExecution())->paginate($pdo, $query, $request, $config, $mapper);
    }

    /**
     * Reads one Offset page using the Host's validated static sort profiles.
     *
     * An excluded whitelist key fails before any SQL. The normalized key and
     * primary direction remain the public sort metadata. Counts, page bounds,
     * driver failures, and transaction ownership follow paginate().
     *
     * @template TItem of array<array-key, mixed>|object
     * @param callable(array<string, mixed>): TItem $mapper
     * @return PageResultDTO<TItem>
     * @throws InvalidPaginationQueryException When the resolved key is excluded from the plan.
     * @throws PaginationExecutionException When page execution reports a failure or a value is invalid.
     */
    public function paginateWithStaticSort(
        PDO $pdo,
        PdoPaginationQueryDescriptor $query,
        PageRequest $request,
        StaticSortPlan $plan,
        callable $mapper,
    ): PageResultDTO {
        return (new PdoPaginationExecution())->paginate($pdo, $query, $request, $plan->config, $mapper, staticSort: $plan);
    }
}
