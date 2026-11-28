<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/** Sort direction backed by its SQL token. */
enum SortDirectionEnum: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';

    /** Compatibility alias for the ascending case published in RC.2. */
    public const ASC = self::Asc;

    /** Compatibility alias for the descending case published in RC.2. */
    public const DESC = self::Desc;
}
