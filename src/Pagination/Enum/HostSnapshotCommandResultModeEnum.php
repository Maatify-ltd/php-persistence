<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/**
 * Chooses an affected-row count or bounded associative PDO rows for a registered
 * command. Bounded rows require a positive limit and fail on overflow.
 */
enum HostSnapshotCommandResultModeEnum
{
    case AFFECTED_ROWS;
    case BOUNDED_ROWS;
}
