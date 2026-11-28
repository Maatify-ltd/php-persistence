<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tooling;

/**
 * Absolute end of the overall budget; null until startBudget() ran.
 */
final class OrchestrationBudget
{
    public static ?float $deadline = null;
}
