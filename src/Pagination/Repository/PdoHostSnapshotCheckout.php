<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;

final readonly class PdoHostSnapshotCheckout
{
    private function __construct(private PdoHostSnapshotSource $source) {}

    /** @internal Only the checkout registered by the source has authority. */
    public static function issue(PdoHostSnapshotSource $source): self
    {
        return new self($source);
    }

    public function state(): HostSnapshotCheckoutStateEnum
    {
        return $this->source->checkoutState($this);
    }

    public function release(): void
    {
        $this->source->releaseCheckout($this);
    }

    private function __clone() {}

    /** @return array<array-key, mixed> */
    public function __serialize(): array
    {
        throw new HostSnapshotPaginationException('A Host snapshot checkout cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new HostSnapshotPaginationException('A Host snapshot checkout cannot be restored.');
    }
}
