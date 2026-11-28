<?php

declare(strict_types=1);

/**
 * Loads the repository's PSR-4 tooling classes when a standalone command
 * runs without the project's Composer installation.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Maatify\\PhpPersistence\\Tooling\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
