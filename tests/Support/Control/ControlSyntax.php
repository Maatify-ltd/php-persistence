<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Control;

final class ControlSyntax
{
    public static function broken(): int
    {
        return 1
    }
}
