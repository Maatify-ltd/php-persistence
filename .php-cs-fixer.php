<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in(__DIR__)
    ->exclude('vendor')
    ->name('*.php')
    ->ignoreDotFiles(false);

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        // Highest PER Coding Style revision the formatter implements (3.0).
        // The 3.1 deltas are verified by scripts/check-per-cs-3-1.php.
        '@PER-CS3x0' => true,
    ])
    ->setFinder($finder);
