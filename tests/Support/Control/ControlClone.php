<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Control;

final class ControlClone
{
    public static function copy(\stdClass $value): \stdClass
    {
        return clone $value;
    }
}
