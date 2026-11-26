<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Closure;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;

/**
 * Registers the total count, filtered count and data statements of one page.
 * The Host supplies non-locking, side-effect-free InnoDB reads and owns the
 * correspondence between SQL placeholders and each statement's declared names.
 */
final readonly class HostSnapshotPagePlan
{
    /** @var list<string> */
    private array $totalNames;
    /** @var list<string> */
    private array $filteredNames;
    /** @var list<string> */
    private array $dataNames;
    /** @var list<string> */
    private array $names;

    /**
     * Validates each statement and its list of unique bare parameter names.
     * A page grant supplies one binding map covering the union of the names.
     * The mapper receives associative rows after statement resources are closed.
     *
     * @param array<array-key, mixed> $totalParameterNames
     * @param array<array-key, mixed> $filteredParameterNames
     * @param array<array-key, mixed> $dataParameterNames
     * @param Closure(array<string, mixed>): (array<array-key, mixed>|object) $mapper
     */
    public function __construct(
        private string $totalSql,
        private string $filteredCountSql,
        private string $dataSql,
        array $totalParameterNames,
        array $filteredParameterNames,
        array $dataParameterNames,
        /** @internal */
        public PaginationConfig $config,
        /** @internal */
        public Closure $mapper,
    ) {
        $this->totalNames = HostSnapshotBindings::names($totalParameterNames);
        $this->filteredNames = HostSnapshotBindings::names($filteredParameterNames);
        $this->dataNames = HostSnapshotBindings::names($dataParameterNames);
        $this->names = array_values(array_unique([...$this->totalNames, ...$this->filteredNames, ...$this->dataNames]));
        $this->descriptor(array_fill_keys($this->names, null));
    }

    /**
     * Validates the exact shared binding map and builds the three per-statement
     * subsets for the registered page.
     *
     * @internal
     * @param array<array-key, mixed> $bindings
     */
    public function descriptor(array $bindings): PdoPaginationQueryDescriptor
    {
        $typed = HostSnapshotBindings::exact($this->names, $bindings);
        try {
            return new PdoPaginationQueryDescriptor(
                $this->totalSql,
                HostSnapshotBindings::subset($this->totalNames, $typed),
                $this->filteredCountSql,
                HostSnapshotBindings::subset($this->filteredNames, $typed),
                $this->dataSql,
                HostSnapshotBindings::subset($this->dataNames, $typed),
            );
        } catch (InvalidPaginationQueryException) {
            throw new HostSnapshotPaginationException('Invalid Host snapshot page plan.');
        }
    }
}
