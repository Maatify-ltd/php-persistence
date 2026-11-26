<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

/** @internal A source-private grant registration with immutable query inputs. */
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
