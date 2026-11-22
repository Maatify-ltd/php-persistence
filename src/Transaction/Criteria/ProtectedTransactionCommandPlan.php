<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Criteria;

use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;

/** The Host warrants that the statement preserves both the session and the transaction. */
final readonly class ProtectedTransactionCommandPlan
{
    /** @var list<string> */
    private array $names;

    /**
     * Registers one trusted InnoDB statement that preserves the session and
     * transaction, including any effects of routines or triggers. The plan
     * admits SELECT, INSERT, UPDATE, DELETE, REPLACE or WITH at the start of
     * the SQL and rejects semicolons. The Host verifies the statement's effects.
     *
     * A null or omitted row bound requests affected-row count. A positive bound
     * requests a list of associative rows and rejects overflow instead of
     * truncating the result.
     *
     * @param array<array-key, mixed> $parameterNames Ordered list of unique names without a colon. Use an empty list for a parameterless statement.
     *
     * @throws ProtectedTransactionException For an invalid statement prefix, parameter declaration or row bound.
     */
    public function __construct(
        /** @internal */
        public string $sql,
        array $parameterNames,
        /** @internal Null requests affected-row count rather than a bounded row list. */
        public ?int $maxRows = null,
    ) {
        if (preg_match('/\A(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|WITH)\b/i', ltrim($sql)) !== 1
            || str_contains($sql, ';') || !array_is_list($parameterNames)
            || ($maxRows !== null && $maxRows < 1)
        ) {
            throw new ProtectedTransactionException('Invalid protected transaction command plan.');
        }
        $names = [];
        foreach ($parameterNames as $name) {
            if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1 || isset($names[$name])) {
                throw new ProtectedTransactionException('Invalid protected transaction parameter names.');
            }
            $names[$name] = true;
        }
        $this->names = array_keys($names);
    }

    /**
     * Validates the exact binding map before SQL and preserves scalar types.
     *
     * @internal
     * @param array<array-key, mixed> $bindings
     * @return array<string, int|string|bool|null>
     */
    public function bind(array $bindings): array
    {
        if (count($bindings) !== count($this->names)) {
            throw new ProtectedTransactionException('Invalid protected transaction bindings.');
        }
        $typed = [];
        foreach ($this->names as $name) {
            if (!array_key_exists($name, $bindings)) {
                throw new ProtectedTransactionException('Invalid protected transaction bindings.');
            }
            $value = $bindings[$name];
            if ($value !== null && !is_int($value) && !is_string($value) && !is_bool($value)) {
                throw new ProtectedTransactionException('Invalid protected transaction binding value.');
            }
            $typed[$name] = $value;
        }

        return $typed;
    }
}
