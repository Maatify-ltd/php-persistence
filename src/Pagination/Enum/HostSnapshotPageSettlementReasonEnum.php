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
    case SOURCE_UNAVAILABLE;
    /** The grant belongs to a transaction generation that has been invalidated. */
    case STALE_CONTEXT;
    /** An earlier admitted attempt already used the grant. */
    case GRANT_CONSUMED;
    /** No admission while reserved. The Host can reuse an otherwise eligible grant in a fresh attempt. */
    case BUSY;
    case PAGE_COMPLETED;
    /** Read or mapping failed, but cursor release and transaction scope were confirmed. */
    case READ_FAILED;
    /** A statement remains outstanding, preventing PDO handoff. */
    case RESOURCE_RELEASE_UNCONFIRMED;
    /** The source could not establish continued use of the qualified transaction. */
    case SCOPE_UNCONFIRMED;
    /** The fallback outcome if ordinary settlement cannot finish. */
    case SETTLEMENT_UNCONFIRMED;
}
