<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\DTO\SnapshotConnectionOutcomeDTO;
use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum;
use Maatify\PhpPersistence\Pagination\Exception\SnapshotPaginationException;
use PDO;
use Throwable;

/**
 * Executes one page in a read-only snapshot on a Host-delegated connection.
 * The Host owns the connection and applies the terminal disposition.
 */
final class PdoSnapshotPaginator
{
    private ?SnapshotConnectionOutcomeDTO $outcome = null;

    private function __construct(private ?PDO $pdo) {}

    /**
     * Registers the Host's exclusive, known-clean read checkout. This performs no I/O.
     * The Host supplies provenance, including absence of next-transaction overrides.
     */
    public static function forExclusiveRead(PDO $pdo): self
    {
        return new self($pdo);
    }

    /**
     * Consumes the one-shot operation before any driver call, including a
     * later refusal. Requires an idle REPEATABLE READ session with autocommit.
     * The page is returned only after confirming commit on the same idle
     * session. On a read or mapper failure, rollback requires confirmed cursor
     * release and continuity. The original throwable escapes and the terminal
     * disposition remains available on this instance.
     *
     * @template TItem of array<array-key, mixed>|object
     *
     * @param callable(array<string, mixed>): TItem $mapper
     *
     * @return PageResultDTO<TItem>
     */
    public function paginate(
        PdoPaginationQueryDescriptor $query,
        PageRequest $request,
        PaginationConfig $config,
        callable $mapper,
    ): PageResultDTO {
        $pdo = $this->pdo;
        if ($pdo === null) {
            throw new SnapshotPaginationException('The snapshot operation was already consumed.');
        }

        // Consume the operation before any driver call or mapper reentry.
        $this->pdo = null;
        $resources = new SnapshotReadResources();
        $disposition = SnapshotConnectionDispositionEnum::DISCARD;
        $stage = 'eligibility';

        try {
            if (! $this->hasEligibleDriver($pdo) || $pdo->inTransaction()) {
                $disposition = SnapshotConnectionDispositionEnum::UNCHANGED;
                throw new SnapshotPaginationException('The Host connection does not qualify for snapshot pagination.');
            }

            [$session, $autocommit] = $this->sessionSettings($pdo, $resources);
            $eligibleSettings = $autocommit && $this->hasRepeatableRead($pdo, $resources);
            $this->assertSession($pdo, $resources, $session, false);
            if (! $eligibleSettings) {
                $disposition = SnapshotConnectionDispositionEnum::UNCHANGED;
                throw new SnapshotPaginationException('Snapshot pagination requires an idle REPEATABLE READ session with autocommit enabled.');
            }

            $stage = 'start';
            $this->control($pdo, 'START TRANSACTION READ ONLY');
            $this->assertSession($pdo, $resources, $session, true);

            try {
                $page = (new PdoPaginationExecution())->paginate($pdo, $query, $request, $config, $mapper, $resources);
            } catch (Throwable $failure) {
                $stage = 'cursor-release';
                try {
                    $resources->release();
                    $stage = 'rollback';
                    $this->finishTransaction($pdo, $resources, $session, 'ROLLBACK AND NO CHAIN NO RELEASE');
                    $disposition = SnapshotConnectionDispositionEnum::REUSABLE;
                } catch (Throwable) {
                    // The checkout remains DISCARD. Preserve the original read failure.
                }

                throw $failure;
            }

            $stage = 'commit';
            $this->finishTransaction($pdo, $resources, $session, 'COMMIT AND NO CHAIN NO RELEASE');

            $disposition = SnapshotConnectionDispositionEnum::REUSABLE;

            return $page;
        } finally {
            $this->outcome = new SnapshotConnectionOutcomeDTO(
                $disposition,
                $disposition === SnapshotConnectionDispositionEnum::DISCARD ? $stage : null,
            );
        }
    }

    /**
     * Returns null until this attempt settles, then the same immutable
     * disposition without asking the database, including after an exception.
     */
    public function connectionOutcome(): ?SnapshotConnectionOutcomeDTO
    {
        return $this->outcome;
    }

    private function __clone() {}

    /**
     * @return array<array-key, mixed>
     *
     * @throws SnapshotPaginationException Always. The instance represents a one-shot, Host-delegated read operation.
     */
    public function __serialize(): array
    {
        throw new SnapshotPaginationException('A snapshot operation cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws SnapshotPaginationException Always. The Host must supply the connection for a new read operation.
     */
    public function __unserialize(array $data): void
    {
        throw new SnapshotPaginationException('A snapshot operation cannot be restored from serialized data.');
    }

    private function hasEligibleDriver(PDO $pdo): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! is_string($driver) || $driver === '') {
            throw new SnapshotPaginationException('Could not determine the PDO driver.');
        }
        if ($driver !== 'mysql') {
            return false;
        }
        $mode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
        if (! in_array($mode, [PDO::ERRMODE_EXCEPTION, PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING], true)) {
            throw new SnapshotPaginationException('Could not determine the PDO error mode.');
        }

        return $mode === PDO::ERRMODE_EXCEPTION;
    }

    /** @return array{string, bool} */
    private function sessionSettings(PDO $pdo, SnapshotReadResources $resources): array
    {
        $row = $this->rows($pdo, $resources, 'SELECT CONNECTION_ID(), @@SESSION.autocommit')[0] ?? [];
        $session = $this->sessionId($row[0] ?? null);
        $autocommit = $row[1] ?? null;
        if (! in_array($autocommit, [0, 1, '0', '1'], true)) {
            throw new SnapshotPaginationException('Could not determine server autocommit status.');
        }

        return [$session, (bool) $autocommit];
    }

    /**
     * Requires the known isolation variables to agree on REPEATABLE READ.
     * These session defaults do not expose a pending one-transaction override.
     */
    private function hasRepeatableRead(PDO $pdo, SnapshotReadResources $resources): bool
    {
        $rows = $this->rows(
            $pdo,
            $resources,
            "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation')",
        );
        if ($rows === []) {
            throw new SnapshotPaginationException('Could not determine the session isolation setting.');
        }
        $repeatableRead = true;
        foreach ($rows as $row) {
            $mode = $row[1] ?? null;
            if (! in_array($mode, ['REPEATABLE-READ', 'READ-COMMITTED', 'READ-UNCOMMITTED', 'SERIALIZABLE'], true)) {
                throw new SnapshotPaginationException('Could not determine the session isolation setting.');
            }
            $repeatableRead = $repeatableRead && $mode === 'REPEATABLE-READ';
        }

        return $repeatableRead;
    }

    private function assertSession(PDO $pdo, SnapshotReadResources $resources, string $session, bool $active): void
    {
        $row = $this->rows($pdo, $resources, 'SELECT CONNECTION_ID()')[0] ?? [];
        if ($this->sessionId($row[0] ?? null) !== $session || $pdo->inTransaction() !== $active) {
            throw new SnapshotPaginationException('Could not confirm the delegated session and transaction state.');
        }
    }

    private function sessionId(mixed $id): string
    {
        if ((! is_int($id) && ! is_string($id)) || preg_match('/\A[1-9][0-9]*\z/', (string) $id) !== 1) {
            throw new SnapshotPaginationException('Could not identify the delegated session.');
        }

        return (string) $id;
    }

    /** @return list<list<mixed>> */
    private function rows(PDO $pdo, SnapshotReadResources $resources, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new SnapshotPaginationException('Could not inspect the delegated session.');
        }

        $resources->track($statement);
        $raw = $statement->fetchAll(PDO::FETCH_NUM);
        $resources->release();
        $rows = [];
        foreach ($raw as $row) {
            if (! is_array($row) || ! array_is_list($row)) {
                throw new SnapshotPaginationException('Could not interpret the delegated session.');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Requires the same active session before sending the explicit end and
     * the same idle session after acknowledgement.
     */
    private function finishTransaction(PDO $pdo, SnapshotReadResources $resources, string $session, string $sql): void
    {
        $this->assertSession($pdo, $resources, $session, true);
        $this->control($pdo, $sql);
        $this->assertSession($pdo, $resources, $session, false);
    }

    private function control(PDO $pdo, string $sql): void
    {
        if ($pdo->exec($sql) === false) {
            throw new SnapshotPaginationException('Snapshot transaction control failed.');
        }
    }
}
