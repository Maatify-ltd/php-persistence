<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;

/**
 * Trusted Host registrations used for page and command authorization.
 * Identifiers follow PHP array-key semantics, including decimal strings PHP
 * normalizes to integer keys.
 */
final readonly class HostSnapshotCatalog
{
    /** @var array<array-key, HostSnapshotPagePlan> */
    private array $pagePlans;

    /** @var array<array-key, HostSnapshotCommandPlan> */
    private array $hostCommands;

    /**
     * Registers plans by non-empty identifier and rejects entries of the wrong
     * plan type. The optional command map defaults to no authorized commands.
     *
     * @param array<array-key, mixed> $pagePlans
     * @param array<array-key, mixed> $hostCommands
     */
    public function __construct(
        array $pagePlans,
        array $hostCommands = [],
    ) {
        $pages = [];
        foreach ($pagePlans as $id => $page) {
            if ($id === '' || ! $page instanceof HostSnapshotPagePlan) {
                throw new HostSnapshotPaginationException('Invalid Host snapshot page registration.');
            }
            $pages[$id] = $page;
        }
        $this->pagePlans = $pages;
        $validated = [];
        foreach ($hostCommands as $id => $command) {
            if ($id === '' || ! $command instanceof HostSnapshotCommandPlan) {
                throw new HostSnapshotPaginationException('Invalid Host snapshot command registration.');
            }
            $validated[$id] = $command;
        }
        $this->hostCommands = $validated;
    }

    /** @internal */
    public function page(string $id): HostSnapshotPagePlan
    {
        return $this->pagePlans[$id] ?? throw new HostSnapshotPaginationException('Unknown Host snapshot page.');
    }

    /** @internal */
    public function command(string $id): HostSnapshotCommandPlan
    {
        return $this->hostCommands[$id] ?? throw new HostSnapshotPaginationException('Unknown Host snapshot command.');
    }
}
