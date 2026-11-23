<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\ValueObject;

use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;

/** @internal Validates and quotes a trusted sort identifier path. */
final class SortIdentifier
{
    public const string SEGMENT_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * @return non-empty-string
     */
    public static function quotePath(string $identifierPath): string
    {
        $segments = explode('.', $identifierPath);
        if (count($segments) > 3) {
            throw new InvalidPaginationConfigurationException('Invalid pagination sort identifier path.');
        }

        foreach ($segments as $segment) {
            if (! preg_match(self::SEGMENT_PATTERN, $segment)) {
                throw new InvalidPaginationConfigurationException('Invalid pagination sort identifier segment.');
            }
        }

        return '`' . implode('`.`', $segments) . '`';
    }
}
