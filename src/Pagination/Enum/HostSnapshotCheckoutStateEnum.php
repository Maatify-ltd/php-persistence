<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotCheckoutStateEnum
{
    case IDLE;
    case TRANSACTION_ACTIVE;
    case TRANSACTION_UNQUALIFIED;
    case BUSY;
    case QUARANTINED;
    case RELEASED;
    case RETIRED;
}
