<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use PDOStatement;
use SensitiveParameter;
use Throwable;

/** @internal Statement receiver. Clearing it also clears traces retaining this wrapper. */
final class ProtectedTransactionStatement
{
    private bool $open = false;

    public function __construct(
        private readonly ProtectedTransactionConnection $connection,
        #[SensitiveParameter]
        private ?PDOStatement $statement,
    ) {}

    /**
     * Binds a bare placeholder name with its PDO type. A driver failure
     * is latched before the error escapes.
     */
    public function bind(string $name, int|string|bool|null $value): void
    {
        $statement = $this->driver();
        $type = match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
        try {
            if (!$statement->bindValue(':' . $name, $value, $type)) {
                throw new ProtectedTransactionException('Unable to bind the protected transaction statement.');
            }
        } catch (Throwable $error) {
            $this->connection->fail($error);
        }
    }

    /**
     * Marks the cursor for cleanup before execution. A successful call returns
     * true. A failing driver result latches and throws an error. Null uses the
     * values bound on the statement.
     *
     * @param array<array-key, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        $statement = $this->driver();
        $this->open = true;
        try {
            if (!$statement->execute($params)) {
                throw new ProtectedTransactionException('Unable to execute the protected transaction statement.');
            }

            return true;
        } catch (Throwable $error) {
            $this->connection->fail($error);
        }
    }

    /**
     * Reads the next associative row. A false result with successful SQLSTATE
     * means EOF, while a failing statement latches and throws.
     *
     * @return array<array-key, mixed>|false
     */
    public function row(): array|false
    {
        $statement = $this->driver();
        try {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false && $statement->errorCode() !== '00000') {
                throw new ProtectedTransactionException('Unable to fetch the protected transaction result.');
            }

            return $row;
        } catch (Throwable $error) {
            $this->connection->fail($error);
        }
    }

    /**
     * Reads the zero-based column, preserving a successful false result.
     * False with a failing SQLSTATE latches and throws a statement error.
     */
    public function fetchColumn(int $column = 0): mixed
    {
        $statement = $this->driver();
        try {
            $value = $statement->fetchColumn($column);
            if ($value === false && $statement->errorCode() !== '00000') {
                throw new ProtectedTransactionException('Unable to fetch the protected transaction result.');
            }

            return $value;
        } catch (Throwable $error) {
            $this->connection->fail($error);
        }
    }

    public function errorCode(): ?string
    {
        return $this->driver()->errorCode();
    }

    public function rowCount(): int
    {
        $statement = $this->driver();
        try {
            return $statement->rowCount();
        } catch (Throwable $error) {
            $this->connection->fail($error);
        }
    }

    /**
     * Releases the result even when SQL execution is fenced. Records a close
     * failure without trying to close the same cursor during settlement.
     */
    public function closeCursor(): bool
    {
        $statement = $this->statement;
        if ($statement === null || !$this->open) {
            return true;
        }
        $this->open = false;
        try {
            if (!$statement->closeCursor()) {
                throw new ProtectedTransactionException('Unable to release the protected transaction result.');
            }

            return true;
        } catch (Throwable $error) {
            $this->connection->fail($error, cursor: true);
        }
    }

    /** Releases the result and detaches the driver statement even if cleanup throws. */
    public function seal(): void
    {
        try {
            $this->closeCursor();
        } finally {
            $this->statement = null;
        }
    }

    /**
     * Checks the receiver fence before returning the statement. A sealed
     * statement rejects further bind, execution and fetch calls.
     */
    private function driver(): PDOStatement
    {
        $this->connection->ensureAvailable();

        return $this->statement ?? throw new ProtectedTransactionException('The protected statement scope is closed.');
    }
}
