<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/**
 * Lifecycle of a single invocation. An admitted invocation runs under the
 * reservation. Admission rejection can settle a new attempt directly.
 */
enum HostSnapshotPageAttemptStateEnum
{
    case New;
    case Running;
    case Terminal;
}
