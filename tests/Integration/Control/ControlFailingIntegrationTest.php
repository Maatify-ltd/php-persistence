<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Control;

use PHPUnit\Framework\TestCase;

final class ControlFailingIntegrationTest extends TestCase
{
    public function testControlFailure(): void
    {
        self::fail('CONTROL: a mandatory integration check fails');
    }
}
