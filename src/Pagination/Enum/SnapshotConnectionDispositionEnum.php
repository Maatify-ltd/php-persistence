<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/** The operation's disposition for the Host's single checkout. */
enum SnapshotConnectionDispositionEnum
{
    case UNCHANGED;
    case REUSABLE;
    case DISCARD;
}
