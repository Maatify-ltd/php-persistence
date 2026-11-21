<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

enum HostSnapshotCommandEffectEnum
{
    case INVALIDATE_GENERATION;
    case PRESERVES_TRANSACTION;
}
