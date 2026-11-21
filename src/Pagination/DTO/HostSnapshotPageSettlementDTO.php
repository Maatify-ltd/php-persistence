<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageDispositionEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum;

final readonly class HostSnapshotPageSettlementDTO
{
    /** @internal Recorded by the source. */
    public function __construct(
        public HostSnapshotPageDispositionEnum $disposition,
        public HostSnapshotPageSettlementReasonEnum $reason,
    ) {}
}
