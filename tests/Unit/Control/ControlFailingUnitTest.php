<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Control;

use PHPUnit\Framework\TestCase;

final class ControlFailingUnitTest extends TestCase
{
    public function testControlFailure() : void{
        self::fail('CONTROL: a mandatory unit check fails');
    }
}
