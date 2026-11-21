<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\DTO\HostSnapshotPageSettlementDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageAttemptStateEnum;

/** @internal Private to the source and the attempt it issued. Contains no driver resources. */
final class HostSnapshotAttemptRecord
{
    public HostSnapshotPageAttemptStateEnum $state = HostSnapshotPageAttemptStateEnum::NEW;
    public ?HostSnapshotPageSettlementDTO $settlement = null;

    public function __construct(public ?HostSnapshotPageGrant $grant) {}

    public function finish(HostSnapshotPageSettlementDTO $settlement): void
    {
        $this->settlement = $settlement;
        $this->grant = null;
        $this->state = HostSnapshotPageAttemptStateEnum::TERMINAL;
    }
}
