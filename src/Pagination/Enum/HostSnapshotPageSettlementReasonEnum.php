<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotPageSettlementReasonEnum
{
    case SOURCE_UNAVAILABLE;
    case STALE_CONTEXT;
    case GRANT_CONSUMED;
    case BUSY;
    case PAGE_COMPLETED;
    case READ_FAILED;
    case RESOURCE_RELEASE_UNCONFIRMED;
    case SCOPE_UNCONFIRMED;
    case SETTLEMENT_UNCONFIRMED;
}
