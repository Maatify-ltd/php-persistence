<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Config;

use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingConfigurationException;

/** Trusted deployment configuration for protected dense UNIQUE ordering. */
final readonly class UniqueOrderingConfig
{
    /** @internal Holds the anchor configuration, which validates identifiers and quotes them through its accessors. */
    public ScopedOrderingConfig $anchor;

    /**
     * Qualifies a global list with a fixed positive anchor ID or a scoped list
     * whose positive scope ID identifies its anchor. Identifiers must come
     * from trusted deployment configuration. The position ceiling bounds SQL
     * integers and the staging slot; the row ceiling bounds scope traversal.
     *
     * @param array<string, mixed> $schemaAttestation Versioned, separately reviewed deployment receipt.
     * @param string $activeSchemaRevision Current deployment revision from independent Host configuration.
     * @throws InvalidOrderingConfigurationException For an invalid ordering profile or bound.
     */
    public function __construct(
        public ScopedOrderingConfig $ordering,
        string $anchorTable,
        string $anchorIdColumn,
        public ?int $globalAnchorId,
        public int $maxPosition,
        public int $maxScopeRows,
        public array $schemaAttestation,
        public string $activeSchemaRevision,
    ) {
        if ($anchorTable === '' || $anchorIdColumn === '') {
            throw new InvalidOrderingConfigurationException('B1 requires an anchor table and primary key.');
        }
        $this->anchor = new ScopedOrderingConfig(table: $anchorTable, idColumn: $anchorIdColumn, deletedAtColumn: null);
        $columns = [$ordering->idColumn, $ordering->orderColumn];
        if ($ordering->scopeColumn !== null) {
            $columns[] = $ordering->scopeColumn;
        }
        if (!str_contains($ordering->table, '.') || !str_contains($anchorTable, '.')
            || strcasecmp($ordering->table, $anchorTable) === 0
            || count(array_unique(array_map('strtolower', $columns))) !== count($columns)
            || $ordering->nullableScope || $ordering->deletedAtColumn !== null || $ordering->updatedAtColumn !== null
            || ($ordering->scopeColumn === null ? ($globalAnchorId ?? 0) < 1 : $globalAnchorId !== null)
            || $maxPosition < 1 || $maxPosition > 4294967295
            || $maxScopeRows < 1 || $maxScopeRows > $maxPosition || $maxScopeRows === PHP_INT_MAX
        ) {
            throw new InvalidOrderingConfigurationException('Invalid protected UNIQUE ordering profile or bounds.');
        }
    }
}
