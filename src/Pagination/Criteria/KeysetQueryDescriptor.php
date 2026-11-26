<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;

/**
 * Trusted registration for one caller-owned InnoDB table with a stable, unique id.
 * The Host owns the predicates and matches each named placeholder to one declaration.
 * The descriptor checks identifiers, declaration shape and the reserved namespace.
 */
final readonly class KeysetQueryDescriptor
{
    private const IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';
    /** @var list<string> */
    private array $columns;

    /** @var list<string> */
    private array $parameterNames;

    private const TABLE = '/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/';

    /**
     * Registers a non-empty query ID and revision for one trusted table, with
     * a trusted, non-blank scope predicate and an optional filter that defaults
     * to no additional filtering. Projection names are unique case-insensitively.
     * Parameter names are unique by exact spelling, and a query with no
     * placeholders may declare none. The Host owns predicate semantics and
     * matches declarations to placeholders. The query ID and revision become
     * part of continuation context.
     *
     * @param list<string> $columns Unqualified source columns.
     * @param list<string> $parameterNames Unique ASCII names without a leading colon.
     * @throws InvalidPaginationQueryException For an invalid registration shape.
     */
    public function __construct(
        private string $queryId,
        private string $revision,
        private string $table,
        array $columns,
        private string $scopePredicate,
        array $parameterNames,
        private string $filterPredicate = '1 = 1',
    ) {
        if ($queryId === '' || $revision === '' || preg_match(self::TABLE, $table) !== 1) {
            throw new InvalidPaginationQueryException('Invalid keyset query identity or table.');
        }

        $this->columns = self::freezeNames($columns, projection: true);
        $this->parameterNames = self::freezeNames($parameterNames, projection: false);

        foreach ([$scopePredicate, $filterPredicate] as $predicate) {
            if (trim($predicate) === '' || stripos($predicate, ':__ks_') !== false) {
                throw new InvalidPaginationQueryException('Invalid keyset query predicate.');
            }
        }
    }

    /**
     * @internal Read trusted registration data for the repository's SQL and cursor context.
     * @return array{
     *     queryId: string, revision: string, table: string, columns: list<string>,
     *     scopePredicate: string, parameterNames: list<string>, filterPredicate: string
     * }
     */
    public function registration(): array
    {
        return [
            'queryId' => $this->queryId,
            'revision' => $this->revision,
            'table' => $this->table,
            'columns' => $this->columns,
            'scopePredicate' => $this->scopePredicate,
            'parameterNames' => $this->parameterNames,
            'filterPredicate' => $this->filterPredicate,
        ];
    }

    /**
     * @param array<array-key, mixed> $names
     * @return list<string>
     */
    private static function freezeNames(array $names, bool $projection): array
    {
        if (! array_is_list($names) || ($projection && $names === [])) {
            throw new InvalidPaginationQueryException('Invalid keyset query identifier list.');
        }

        $seen = [];
        $frozen = [];
        foreach ($names as $name) {
            if (! is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidPaginationQueryException('Invalid keyset query identifier.');
            }

            $normalized = strtolower($name);
            $reserved = $projection ? $normalized === '__ks_id' : str_starts_with($normalized, '__ks_');
            $key = $projection ? $normalized : $name;
            if ($reserved || isset($seen[$key])) {
                throw new InvalidPaginationQueryException('Duplicate or reserved keyset query identifier.');
            }
            $seen[$key] = true;
            $frozen[] = $name;
        }

        return $frozen;
    }
}
