<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/**
 * Authority and availability of a Host snapshot checkout. Only the qualified
 * active state can authorize pages. Released ends one handle's authority, while
 * Retired denotes permanent retirement of its source.
 */
enum HostSnapshotCheckoutStateEnum
{
    /** The checkout is ready to begin. Release also requires resolved transaction settings. */
    case Idle;
    case TransactionActive;
    /** An active transaction outlived invalidation and cannot authorize new pages. */
    case TransactionUnqualified;
    /** A reserved operation is still in progress. */
    case Busy;
    /** The source fences further SQL pending the Host's retirement or discard decision. */
    case Quarantined;
    case Released;
    case Retired;
}
