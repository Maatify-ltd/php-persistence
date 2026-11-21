<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotCommandResultModeEnum
{
    case AFFECTED_ROWS;
    case BOUNDED_ROWS;
}
