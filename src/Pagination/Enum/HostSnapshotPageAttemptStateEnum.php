<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotPageAttemptStateEnum
{
    case NEW;
    case RUNNING;
    case TERMINAL;
}
