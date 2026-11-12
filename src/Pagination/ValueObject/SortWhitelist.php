<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\ValueObject;

use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;

/**
 * Maps public sort keys to validated, quoted SQL identifier paths.
 *
 * The caller defines the mapping from trusted identifiers. A request can
 * select a key from it but cannot supply the SQL identifier.
 */
final readonly class SortWhitelist
{
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var non-empty-array<non-empty-string, non-empty-string> */
    private array $quotedSorts;

    /**
     * Accepts a non-empty map of public keys to identifiers with one to three
     * dot-separated segments. Each key and segment starts with an ASCII letter
     * or underscore, followed by ASCII letters, digits, or underscores.
     *
     * @param non-empty-array<non-empty-string, non-empty-string> $sorts
     *
     * @throws InvalidPaginationConfigurationException When the mapping is empty or invalid.
     */
    public function __construct(array $sorts)
    {
        $this->quotedSorts = $this->normalizeSorts($sorts);
    }

    public function contains(string $key): bool
    {
        return array_key_exists($key, $this->quotedSorts);
    }

    /**
     * Returns the SQL identifier for an allowed key, with each segment quoted.
     *
     * @return non-empty-string
     *
     * @throws InvalidPaginationConfigurationException When the key is absent.
     */
    public function quotedIdentifierFor(string $key): string
    {
        if (! $this->contains($key)) {
            throw new InvalidPaginationConfigurationException('Unknown pagination sort key.');
        }

        return $this->quotedSorts[$key];
    }

    /**
     * @param array<mixed, mixed> $sorts
     *
     * @return non-empty-array<non-empty-string, non-empty-string>
     */
    private function normalizeSorts(array $sorts): array
    {
        if ($sorts === []) {
            throw new InvalidPaginationConfigurationException('Sort whitelist cannot be empty.');
        }

        $quotedSorts = [];
        foreach ($sorts as $key => $identifierPath) {
            if (! is_string($key) || $key === '') {
                throw new InvalidPaginationConfigurationException('Invalid pagination sort key.');
            }

            if (! preg_match(self::IDENTIFIER_PATTERN, $key)) {
                throw new InvalidPaginationConfigurationException('Invalid pagination sort key.');
            }

            if (! is_string($identifierPath)) {
                throw new InvalidPaginationConfigurationException('Invalid pagination sort identifier.');
            }

            $quotedSorts[$key] = $this->quoteIdentifierPath($identifierPath);
        }

        return $quotedSorts;
    }

    /**
     * @return non-empty-string
     */
    private function quoteIdentifierPath(string $identifierPath): string
    {
        $segments = explode('.', $identifierPath);
        if (count($segments) > 3) {
            throw new InvalidPaginationConfigurationException('Invalid pagination sort identifier path.');
        }

        foreach ($segments as $segment) {
            if (! preg_match(self::IDENTIFIER_PATTERN, $segment)) {
                throw new InvalidPaginationConfigurationException('Invalid pagination sort identifier segment.');
            }
        }

        return '`' . implode('`.`', $segments) . '`';
    }
}
