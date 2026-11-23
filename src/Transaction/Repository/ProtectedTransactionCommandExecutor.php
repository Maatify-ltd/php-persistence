<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Throwable;

/** @internal Executes one registered command against a scoped driver receiver. */
final readonly class ProtectedTransactionCommandExecutor
{
    public function __construct(private ProtectedTransactionConnection $connection) {}

    /**
     * Executes a validated plan against the reserved receiver. Exceeding the
     * row bound raises an exception instead of returning a partial result.
     *
     * @param array<string, int|string|bool|null> $params Bindings already validated by the plan.
     * @param bool $releaseBeforeReturn Fold cursor release into this command's result. Otherwise the checkout owns release.
     *                                 An earlier command error retains precedence over cursor cleanup.
     * @return int|list<array<array-key, mixed>>
     */
    public function run(
        ProtectedTransactionCommandPlan $command,
        array $params,
        bool $releaseBeforeReturn = false,
    ): int|array {
        $statement = $this->connection->prepare($command->sql);
        $primary = null;
        try {
            foreach ($params as $name => $value) {
                $statement->bind($name, $value);
            }
            $statement->execute();
            if ($command->maxRows === null) {
                return $statement->rowCount();
            }

            $rows = [];
            while (($row = $statement->row()) !== false) {
                if (count($rows) === $command->maxRows) {
                    throw new ProtectedTransactionException('The protected transaction result exceeds its row bound.');
                }
                $rows[] = $row;
            }

            return $rows;
        } catch (Throwable $failure) {
            $primary = $failure;
            throw $failure;
        } finally {
            if ($releaseBeforeReturn) {
                try {
                    $statement->seal();
                } catch (Throwable $cleanupFailure) {
                    if ($primary === null) {
                        throw $cleanupFailure;
                    }
                } finally {
                    $this->connection->forgetStatement($statement);
                }
            }
        }
    }
}
