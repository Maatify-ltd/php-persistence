<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;

/** Trusted Host registration of page and command plans. */
final readonly class HostSnapshotCatalog
{
    /** @var array<string, HostSnapshotCommandPlan> */
    private array $hostCommands;

    /**
     * @param array<array-key, mixed> $pagePlans
     * @param array<array-key, mixed> $hostCommands
     */
    public function __construct(
        private array $pagePlans,
        array $hostCommands = [],
    ) {
        $validated = [];
        foreach ($hostCommands as $id => $command) {
            if (! is_string($id) || $id === '' || ! $command instanceof HostSnapshotCommandPlan) {
                throw new HostSnapshotPaginationException('Invalid Host snapshot command registration.');
            }
            $validated[$id] = $command;
        }
        $this->hostCommands = $validated;
    }

    /** @internal */
    public function command(string $id): HostSnapshotCommandPlan
    {
        return $this->hostCommands[$id] ?? throw new HostSnapshotPaginationException('Unknown Host snapshot command.');
    }
}
