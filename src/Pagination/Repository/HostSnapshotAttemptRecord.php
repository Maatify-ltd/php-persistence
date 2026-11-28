<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPageGrant;
use Maatify\PhpPersistence\Pagination\DTO\HostSnapshotPageSettlementDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageAttemptStateEnum;

/** @internal Private to the source and the attempt it issued. Contains no driver resources. */
final class HostSnapshotAttemptRecord
{
    public HostSnapshotPageAttemptStateEnum $state = HostSnapshotPageAttemptStateEnum::New;
    /** Null until this invocation publishes its terminal, connection-free outcome. */
    public ?HostSnapshotPageSettlementDTO $settlement = null;

    public function __construct(public ?HostSnapshotPageGrant $grant) {}

    /**
     * Publishes the outcome and drops the grant reference. For an admitted
     * attempt, the source completes this step while holding the reservation.
     */
    public function finish(HostSnapshotPageSettlementDTO $settlement): void
    {
        $this->settlement = $settlement;
        $this->grant = null;
        $this->state = HostSnapshotPageAttemptStateEnum::Terminal;
    }
}
