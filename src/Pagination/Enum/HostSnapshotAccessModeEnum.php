<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotAccessModeEnum: string
{
    case ReadWrite = 'READ WRITE';
    case ReadOnly = 'READ ONLY';
}
