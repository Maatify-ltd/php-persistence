<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use JsonSerializable;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageDispositionEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum;

/**
 * Immutable, connection-free disposition and reason for one page attempt.
 * The disposition describes the Host's authority after the attempt. The
 * reason explains the outcome and does not replace its original throwable.
 */
final readonly class HostSnapshotPageSettlementDTO implements JsonSerializable
{
    /** @internal Recorded by the source. */
    public function __construct(
        public HostSnapshotPageDispositionEnum $disposition,
        public HostSnapshotPageSettlementReasonEnum $reason,
    ) {}

    /**
     * Projects the recorded enum case names as strings.
     *
     * @return array{disposition: string, reason: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'disposition' => $this->disposition->name,
            'reason' => $this->reason->name,
        ];
    }
}
