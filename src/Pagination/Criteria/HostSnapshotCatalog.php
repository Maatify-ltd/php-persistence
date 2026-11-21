<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

/** Trusted Host registration of page and command plans. */
final readonly class HostSnapshotCatalog
{
    /**
     * @param array<array-key, mixed> $pagePlans
     * @param array<array-key, mixed> $hostCommands
     */
    public function __construct(
        private array $pagePlans,
        private array $hostCommands = [],
    ) {}
}
