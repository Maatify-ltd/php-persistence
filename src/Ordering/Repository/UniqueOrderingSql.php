<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Repository;

use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionStatement;
use Throwable;

/** @internal Executes bounded B1 statements with explicit cursor settlement. */
final readonly class UniqueOrderingSql
{
    public function __construct(private ProtectedTransactionConnection $connection) {}

    /**
     * @param array<string, int|string> $bindings
     * @return list<array<array-key, mixed>>
     */
    public function rows(string $sql, array $bindings = []): array
    {
        return $this->execute($sql, $bindings, static function (ProtectedTransactionStatement $statement): array {
            $rows = [];
            while (($row = $statement->row()) !== false) {
                $rows[] = $row;
            }

            return $rows;
        });
    }

    /** @param array<string, int|string> $bindings */
    public function update(string $sql, array $bindings): int
    {
        return $this->execute($sql, $bindings, static fn(ProtectedTransactionStatement $statement): int => $statement->rowCount());
    }

    /**
     * @template T
     * @param array<string, int|string> $bindings
     * @param callable(ProtectedTransactionStatement): T $result
     * @return T
     */
    private function execute(string $sql, array $bindings, callable $result): mixed
    {
        $statement = $this->connection->prepare($sql);
        $primary = null;
        try {
            foreach ($bindings as $name => $value) {
                $statement->bind($name, $value);
            }
            $statement->execute();

            return $result($statement);
        } catch (Throwable $failure) {
            $primary = $failure;
            throw $failure;
        } finally {
            try {
                $statement->seal();
            } catch (Throwable $cleanup) {
                if ($primary === null) {
                    throw $cleanup;
                }
            } finally {
                $this->connection->forgetStatement($statement);
            }
        }
    }

    public static function reject(string $reason, string $message): never
    {
        throw new OrderingExecutionException($message, meta: ['reason' => $reason]);
    }
}
