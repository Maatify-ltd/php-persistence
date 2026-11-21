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

    /** @return array{session: string, autocommit: bool, active: bool} */
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

    /** @param array<string, int|string|bool|null> $bindings
     *  @return list<array<array-key, mixed>>
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

    public function handoff(HostSnapshotRetirementDispositionEnum $disposition): HostSnapshotRetirementDTO
    {
        return new HostSnapshotRetirementDTO($disposition, $this->pdo);
    }

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
