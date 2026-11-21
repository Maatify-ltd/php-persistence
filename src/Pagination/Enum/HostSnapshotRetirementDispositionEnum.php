<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotRetirementDispositionEnum
{
    case RETURNED_IDLE;
    case RETURNED_FOR_RECONCILIATION;
}
