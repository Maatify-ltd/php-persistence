<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;

/** @internal */
final class HostSnapshotBindings
{
    /** @param array<array-key, mixed> $names
     *  @return list<string>
     */
    public static function names(array $names): array
    {
        if (! array_is_list($names)) {
            throw new HostSnapshotPaginationException('Invalid Host snapshot parameter names.');
        }
        $valid = [];
        foreach ($names as $name) {
            if (! is_string($name) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) || isset($valid[$name])) {
                throw new HostSnapshotPaginationException('Invalid Host snapshot parameter names.');
            }
            $valid[$name] = true;
        }

        return array_keys($valid);
    }

    /**
     * @param list<string> $names
     * @param array<array-key, mixed> $bindings
     * @return array<string, string|int|bool|null>
     */
    public static function exact(array $names, array $bindings): array
    {
        if (count($names) !== count($bindings)) {
            throw new HostSnapshotPaginationException('Invalid Host snapshot bindings.');
        }

        return self::subset($names, $bindings);
    }

    /**
     * @param list<string> $names
     * @param array<array-key, mixed> $bindings
     * @return array<string, string|int|bool|null>
     */
    public static function subset(array $names, array $bindings): array
    {
        $typed = [];
        foreach ($names as $name) {
            if (! array_key_exists($name, $bindings)) {
                throw new HostSnapshotPaginationException('Invalid Host snapshot bindings.');
            }
            $value = $bindings[$name];
            if ($value !== null && ! is_bool($value) && ! is_int($value) && ! is_string($value)) {
                throw new HostSnapshotPaginationException('Invalid Host snapshot bindings.');
            }
            $typed[$name] = $value;
        }

        return $typed;
    }
}
