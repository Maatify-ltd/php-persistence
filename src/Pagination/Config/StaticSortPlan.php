<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Config;

use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\ValueObject\SortIdentifier;

/**
 * Reusable Host-defined sort profiles for one Offset query shape.
 *
 * The profile map declares the eligible keys. Identifiers come from trusted
 * application configuration. The Host defines a total order on the actual
 * result rows, including rows produced by joins.
 */
final readonly class StaticSortPlan
{
    /** @var array<string, non-empty-list<array{identifier: non-empty-string, direction: SortDirectionEnum|null}>|null> */
    private array $profiles;

    /**
     * Each whitelisted profile key declares an explicit null for the legacy
     * primary/tie-breaker order or one to three trailing terms. Each term has
     * an identifier path and may have a fixed SortDirectionEnum direction.
     * Omitting the direction inherits the effective primary direction.
     * The default key must be declared and at least one profile must be composite.
     *
     * @param array<mixed> $profiles
     * @throws InvalidPaginationConfigurationException When any profile is invalid.
     */
    public function __construct(
        /** @internal */
        public PaginationConfig $config,
        array $profiles,
    ) {
        if (! array_key_exists($config->defaultSortBy, $profiles)) {
            throw new InvalidPaginationConfigurationException('The default pagination sort key requires an explicit static profile.');
        }

        $validated = [];
        $hasComposite = false;
        foreach ($profiles as $key => $profile) {
            if (! is_string($key) || ! $config->sortWhitelist->contains($key)) {
                throw new InvalidPaginationConfigurationException('Static pagination profile keys must be whitelisted.');
            }

            $validated[$key] = $profile === null
                ? null
                : self::validateTerms($profile, $config->sortWhitelist->quotedIdentifierFor($key));
            $hasComposite = $hasComposite || $profile !== null;
        }

        if (! $hasComposite) {
            throw new InvalidPaginationConfigurationException('At least one static pagination profile must be composite.');
        }

        $this->profiles = $validated;
    }

    /**
     * Returns quoted trailing terms for an already-normalized sort key.
     *
     * @internal
     * @return non-empty-list<array{identifier: non-empty-string, direction: SortDirectionEnum|null}>|null
     * @throws InvalidPaginationQueryException When the key is excluded from the plan.
     */
    public function trailingTermsFor(string $key): ?array
    {
        if (! array_key_exists($key, $this->profiles)) {
            throw new InvalidPaginationQueryException('The requested pagination sort key is excluded from the static plan.');
        }

        return $this->profiles[$key];
    }

    /** @return non-empty-list<array{identifier: non-empty-string, direction: SortDirectionEnum|null}> */
    private static function validateTerms(mixed $profile, string $primary): array
    {
        if (! is_array($profile) || ! array_is_list($profile) || $profile === [] || count($profile) > 3) {
            throw new InvalidPaginationConfigurationException('Composite pagination profiles require one to three trailing terms.');
        }

        $seen = [strtolower($primary) => true];
        $terms = [];
        foreach ($profile as $term) {
            if (! is_array($term) || ! is_string($term['identifier'] ?? null)
                || array_diff_key($term, ['identifier' => true, 'direction' => true]) !== []
            ) {
                throw new InvalidPaginationConfigurationException('Invalid static pagination sort term.');
            }

            if (array_key_exists('direction', $term) && ! $term['direction'] instanceof SortDirectionEnum) {
                throw new InvalidPaginationConfigurationException('A fixed static pagination sort direction must be a SortDirectionEnum.');
            }

            $identifier = SortIdentifier::quotePath($term['identifier']);
            $comparison = strtolower($identifier);
            if (isset($seen[$comparison])) {
                throw new InvalidPaginationConfigurationException('Duplicate sort identifier in a static pagination profile.');
            }

            $seen[$comparison] = true;
            $terms[] = ['identifier' => $identifier, 'direction' => $term['direction'] ?? null];
        }

        return $terms;
    }
}
