<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotAccessModeEnum: string
{
    case READ_WRITE = 'READ WRITE';
    case READ_ONLY = 'READ ONLY';
}
