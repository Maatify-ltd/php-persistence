<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\DTO\HostSnapshotRetirementDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotRetirementDispositionEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotRowLimitExceededException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use PDO;
use PDOStatement;
use Throwable;

/** @internal Owns B's driver resources. Driver objects stay out of helper arguments. */
final class HostSnapshotConnection
{
    private ?PDOStatement $statement = null;

    public function __construct(private PDO $pdo) {}

    /**
     * Reads the server session identifier and autocommit flag and samples PDO
     * activity. The activity flag is an observation, not proof of continuity.
     *
     * @return array{session: string, autocommit: bool, active: bool}
     */
    public function inspect(): array
    {
        $rows = $this->rows('SELECT CONNECTION_ID() AS session_id, @@autocommit AS autocommit', [], 1);
        $row = array_change_key_case($rows[0] ?? []);
        $id = $row['session_id'] ?? null;
        $autocommit = $row['autocommit'] ?? null;
        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]*\z/', (string) $id)
            || ! in_array($autocommit, [0, 1, '0', '1'], true)
        ) {
            throw new HostSnapshotPaginationException('Unable to confirm the Host snapshot session.');
        }

        return ['session' => (string) $id, 'autocommit' => (string) $autocommit === '1', 'active' => $this->pdo->inTransaction()];
    }

    /**
     * Reads bounded rows, translating overflow into a pagination execution error.
     * Positional reads preserve column cardinality for count queries.
     *
     * @param array<string, int|string|bool|null> $bindings
     * @return list<array<array-key, mixed>>
     */
    public function rows(string $sql, array $bindings, int $maxRows, bool $positional = false): array
    {
        try {
            $result = $this->execute($sql, $bindings, $maxRows, $positional);
        } catch (HostSnapshotRowLimitExceededException) {
            throw new PaginationExecutionException('The Host snapshot result exceeds its row limit.');
        }
        assert(is_array($result));

        return $result;
    }

    /**
     * Runs one statement while owning its cursor. A null row limit requests an
     * affected-row count. A finite limit rejects overflow, preserving the keys of
     * associative rows unless a positional read was requested. Failed cursor
     * release keeps the resource outstanding and preserves any earlier failure.
     *
     * @param array<string, int|string|bool|null> $bindings
     * @return int|list<array<array-key, mixed>>
     */
    public function execute(string $sql, array $bindings, ?int $maxRows, bool $positional = false): int|array
    {
        if ($this->statement !== null) {
            throw new HostSnapshotPaginationException('Host snapshot resources are unresolved.');
        }
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new HostSnapshotPaginationException('Unable to prepare the Host snapshot statement.');
        }
        $this->statement = $statement;
        $failure = null;

        try {
            foreach ($bindings as $name => $value) {
                $type = match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                };
                if (! $statement->bindValue(':' . $name, $value, $type)) {
                    throw new HostSnapshotPaginationException('Unable to bind the Host snapshot statement.');
                }
            }
            if (! $statement->execute()) {
                throw new HostSnapshotPaginationException('Unable to execute the Host snapshot statement.');
            }
            if ($maxRows === null) {
                return $statement->rowCount();
            }

            $rows = [];
            $fetchMode = $positional ? PDO::FETCH_NUM : PDO::FETCH_ASSOC;
            while (($row = $statement->fetch($fetchMode)) !== false) {
                if (count($rows) === $maxRows) {
                    throw new HostSnapshotRowLimitExceededException();
                }
                $rows[] = $row;
            }
            if ($statement->errorCode() !== '00000') {
                throw new HostSnapshotPaginationException('Unable to fetch the Host snapshot result.');
            }

            return $rows;
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try {
                if (! $statement->closeCursor()) {
                    throw new HostSnapshotPaginationException('Unable to release the Host snapshot statement.');
                }
                $this->statement = null;
            } catch (Throwable $cleanup) {
                if ($failure === null) {
                    throw $cleanup;
                }
            }
        }
    }

    /** Sends explicit transaction control only after the previous cursor is released. */
    public function control(string $sql): void
    {
        if ($this->statement !== null || $this->pdo->exec($sql) === false) {
            throw new HostSnapshotPaginationException('Unable to confirm the Host snapshot command.');
        }
    }

    public function transactionActive(): bool
    {
        return $this->pdo->inTransaction();
    }

    /** Builds the handoff with the same PDO and the disposition determined by the source. */
    public function handoff(HostSnapshotRetirementDispositionEnum $disposition): HostSnapshotRetirementDTO
    {
        return new HostSnapshotRetirementDTO($disposition, $this->pdo);
    }

    /**
     * Drops the cursor and PDO references without an explicit server acknowledgement.
     * The driver may perform I/O when it destroys the last reference.
     */
    public function detach(): void
    {
        try {
            $this->statement = null;
        } finally {
            // A native PDO destructor may issue an implicit rollback here.
            unset($this->pdo);
        }
    }

    public function resourcesReleased(): bool
    {
        return $this->statement === null;
    }
}
