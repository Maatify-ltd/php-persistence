<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Closure;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;

/** A trusted Host registration of the three statements that make up one page. */
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
