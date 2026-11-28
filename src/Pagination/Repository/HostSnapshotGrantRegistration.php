<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPagePlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;

/**
 * A grant registration with immutable inputs for one authorized page.
 * The source compares the captured checkout and transaction generation by
 * object identity to prevent admission in a different checkout or generation.
 *
 * @internal
 */
final class HostSnapshotGrantRegistration
{
    /** Set when an attempt is admitted under the shared reservation, before database I/O. */
    public bool $consumed = false;

    public function __construct(
        public readonly object $checkoutIdentity,
        public readonly object $generation,
        public readonly HostSnapshotPagePlan $plan,
        public readonly PdoPaginationQueryDescriptor $query,
        public readonly PageRequest $request,
    ) {}
}
