<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/**
 * Authority and availability of a Host snapshot checkout. Only the qualified
 * active state can authorize pages. RELEASED ends one handle's authority, while
 * RETIRED denotes permanent retirement of its source.
 */
enum HostSnapshotCheckoutStateEnum
{
    /** The checkout is ready to begin. Release also requires resolved transaction settings. */
    case IDLE;
    case TRANSACTION_ACTIVE;
    /** An active transaction outlived invalidation and cannot authorize new pages. */
    case TRANSACTION_UNQUALIFIED;
    /** A reserved operation is still in progress. */
    case BUSY;
    /** The source fences further SQL pending the Host's retirement or discard decision. */
    case QUARANTINED;
    case RELEASED;
    case RETIRED;
}
