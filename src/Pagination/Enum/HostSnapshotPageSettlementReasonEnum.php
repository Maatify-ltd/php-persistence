<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/**
 * Explains a page attempt's disposition. The reason separates pre-admission
 * rejection, a result or read error within a confirmed scope, and uncertainty
 * requiring Host action. For admission rejection, source availability is
 * checked first, followed by generation, grant consumption and reservation.
 */
enum HostSnapshotPageSettlementReasonEnum
{
    case SourceUnavailable;
    /** The grant belongs to a transaction generation that has been invalidated. */
    case StaleContext;
    /** An earlier admitted attempt already used the grant. */
    case GrantConsumed;
    /** No admission while reserved. The Host can reuse an otherwise eligible grant in a fresh attempt. */
    case Busy;
    case PageCompleted;
    /** Read or mapping failed, but cursor release and transaction scope were confirmed. */
    case ReadFailed;
    /** A statement remains outstanding, preventing PDO handoff. */
    case ResourceReleaseUnconfirmed;
    /** The source could not establish continued use of the qualified transaction. */
    case ScopeUnconfirmed;
    /** The fallback outcome if ordinary settlement cannot finish. */
    case SettlementUnconfirmed;

    /** @internal Stable code for settlement JSON. */
    public function settlementCode(): string
    {
        return match ($this) {
            self::SourceUnavailable => 'SOURCE_UNAVAILABLE',
            self::StaleContext => 'STALE_CONTEXT',
            self::GrantConsumed => 'GRANT_CONSUMED',
            self::Busy => 'BUSY',
            self::PageCompleted => 'PAGE_COMPLETED',
            self::ReadFailed => 'READ_FAILED',
            self::ResourceReleaseUnconfirmed => 'RESOURCE_RELEASE_UNCONFIRMED',
            self::ScopeUnconfirmed => 'SCOPE_UNCONFIRMED',
            self::SettlementUnconfirmed => 'SETTLEMENT_UNCONFIRMED',
        };
    }
}
