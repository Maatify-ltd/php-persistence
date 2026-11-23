<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\ValueObject;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;

/**
 * Holds the validated order shared by Offset SQL and D result metadata.
 *
 * Labeled terms are the request in order, or the default when it is empty.
 * The optional unlabeled term is the automatically appended Host tie.
 *
 * @internal
 * @phpstan-type SortTerm array{key: string|null, identifier: non-empty-string, direction: SortDirectionEnum}
 * @phpstan-type LabeledSortTerm array{key: string, identifier: non-empty-string, direction: SortDirectionEnum}
 */
final readonly class ResolvedDynamicSort
{
    /**
     * @param non-empty-list<LabeledSortTerm> $labeledTerms
     * @param array{key: null, identifier: non-empty-string, direction: SortDirectionEnum}|null $automaticTie
     */
    private function __construct(
        private array $labeledTerms,
        private ?array $automaticTie,
        private bool $hasSelection,
    ) {}

    /**
     * Resolves trusted paths before PDO is used. An ambiguous default/tie
     * configuration takes precedence over an invalid request. Once the request
     * terms are valid in shape, qualifier conflicts take precedence over
     * duplicate and tie selection errors.
     *
     * @param array<mixed> $sorts
     * @throws InvalidPaginationConfigurationException For conflicting qualifier casing.
     * @throws InvalidPaginationQueryException For an invalid selection or reserved tie key.
     */
    public static function resolve(PaginationConfig $config, PageRequest $request, array $sorts, bool $allowTieBreakerSelection): self
    {
        $whitelist = $config->sortWhitelist;
        $default = $whitelist->quotedIdentifierFor($config->defaultSortBy);
        $tiePath = $whitelist->quotedIdentifierFor($config->tieBreakerSortBy);
        $defaultIsTie = self::sameIdentifier($default, $tiePath);
        $automaticTie = ['key' => null, 'identifier' => $tiePath, 'direction' => $config->tieBreakerDirection];

        if ($request->sortBy !== null || $request->sortDirection !== null || ! array_is_list($sorts) || count($sorts) > 3) {
            throw new InvalidPaginationQueryException('Dynamic pagination requires a list of up to three terms and a request without sort values.');
        }
        if ($sorts === []) {
            return new self(
                [['key' => $config->defaultSortBy, 'identifier' => $default, 'direction' => $config->defaultSortDirection]],
                $defaultIsTie ? null : $automaticTie,
                false,
            );
        }

        $terms = self::normalizeTerms($sorts, $whitelist);
        $last = count($terms) - 1;
        $previous = [];
        $selectionError = null;
        foreach ($terms as $index => $term) {
            $identifier = $term['identifier'];
            $isTie = self::sameIdentifier($identifier, $tiePath);
            $folded = strtolower($identifier);
            if (isset($previous[$folded]) && self::sameIdentifier($identifier, $previous[$folded])) {
                $selectionError ??= 'Duplicate identifier in dynamic pagination sort.';
            }
            $previous[$folded] = $identifier;

            if ($term['key'] === $config->tieBreakerSortBy && ! $allowTieBreakerSelection) {
                $selectionError ??= 'The Host has reserved the dynamic pagination tie-breaker key.';
            }
            if ($isTie) {
                if ($index !== $last) {
                    $selectionError ??= 'A selected tie-breaker must be the last dynamic pagination term.';
                }
                $automaticTie = null;
            }
        }

        if ($selectionError !== null) {
            throw new InvalidPaginationQueryException($selectionError);
        }

        return new self($terms, $automaticTie, true);
    }

    public function primaryKey(): string
    {
        return $this->labeledTerms[0]['key'];
    }

    public function primaryDirection(): SortDirectionEnum
    {
        return $this->labeledTerms[0]['direction'];
    }

    /** @return list<LabeledSortTerm> */
    public function selectedTerms(): array
    {
        return $this->hasSelection ? $this->labeledTerms : [];
    }

    /** @return non-empty-list<SortTerm> */
    public function effectiveTerms(): array
    {
        return $this->automaticTie === null ? $this->labeledTerms : [...$this->labeledTerms, $this->automaticTie];
    }

    /**
     * Restricts construction to the resolver so quoted identifiers cannot be injected through native hydration.
     *
     * @param array<mixed> $data
     * @throws PaginationExecutionException Always.
     */
    public function __unserialize(array $data): void
    {
        throw new PaginationExecutionException('A resolved dynamic pagination sort must come from the sort resolver.');
    }

    /**
     * @param non-empty-list<mixed> $sorts
     * @return non-empty-list<LabeledSortTerm>
     * @throws InvalidPaginationQueryException For malformed terms, unknown keys or invalid directions.
     */
    private static function normalizeTerms(array $sorts, SortWhitelist $whitelist): array
    {
        $terms = [];
        foreach ($sorts as $term) {
            if (! is_array($term) || count($term) !== 2 || ! is_string($term['key'] ?? null)
                || ! is_string($term['direction'] ?? null) || ! $whitelist->contains($term['key'])
            ) {
                throw new InvalidPaginationQueryException('Invalid dynamic pagination sort term or key.');
            }

            $direction = SortDirectionEnum::tryFrom(strtoupper($term['direction']));
            if ($direction === null) {
                throw new InvalidPaginationQueryException('Invalid dynamic pagination sort direction.');
            }

            $terms[] = ['key' => $term['key'], 'identifier' => $whitelist->quotedIdentifierFor($term['key']), 'direction' => $direction];
        }

        return $terms;
    }

    /**
     * Column spelling folds in ASCII. Qualifiers must match exactly when the
     * otherwise folded paths would collide. Different paths are Host-owned.
     *
     * @throws InvalidPaginationConfigurationException For a case-only qualifier conflict.
     */
    private static function sameIdentifier(string $left, string $right): bool
    {
        if (strtolower($left) !== strtolower($right)) {
            return false;
        }

        $qualifierLength = strrpos($left, '.');
        if ($qualifierLength !== false && substr($left, 0, $qualifierLength) !== substr($right, 0, $qualifierLength)) {
            throw new InvalidPaginationConfigurationException('Ambiguous qualifier casing in dynamic pagination sort identifiers.');
        }

        return true;
    }
}
