<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;

/** Opaque authorization for one admitted attempt. */
final class HostSnapshotPageGrant
{
    private function __construct() {}

    /** @internal A grant without a source registration has no authority. */
    public static function issue(): self
    {
        return new self();
    }

    private function __clone() {}

    /** @return array<array-key, mixed> */
    public function __serialize(): array
    {
        throw new HostSnapshotPaginationException('A Host snapshot grant cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new HostSnapshotPaginationException('A Host snapshot grant cannot be restored.');
    }
}
