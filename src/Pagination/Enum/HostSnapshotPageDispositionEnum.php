<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotPageDispositionEnum
{
    case NOT_ACCEPTED;
    case RETURNED_TO_HOST;
    case HOST_ACTION_REQUIRED;
}
