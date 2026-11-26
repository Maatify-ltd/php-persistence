<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/**
 * Condition of the same PDO handed back after source retirement. A known idle
 * session is distinguished from one the Host must reconcile before reuse.
 */
enum HostSnapshotRetirementDispositionEnum
{
    case RETURNED_IDLE;
    case RETURNED_FOR_RECONCILIATION;
}
