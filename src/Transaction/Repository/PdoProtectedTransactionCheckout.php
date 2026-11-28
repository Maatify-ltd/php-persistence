<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Closure;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\DTO\ProtectedTransactionSettlementDTO;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

/**
 * One exclusive Host delegation for one Host-directed write transaction.
 * The checkout serializes access and reports a terminal disposition for the
 * Host to retrieve or discard the connection and reconcile uncertain writes.
 */
final class PdoProtectedTransactionCheckout
{
    private ?string $session = null;
    private bool $mariaDb = false;
    private bool $busy = false;
    private ?ProtectedTransactionSettlementDTO $outcome = null;

    private function __construct(
        #[SensitiveParameter]
        private ?PDO $pdo,
        private readonly ProtectedTransactionCommandCatalog $catalog,
    ) {}

    /**
     * Accepts an idle, non-persistent pdo_mysql connection (PDO or Pdo\Mysql)
     * with autocommit enabled, exception mode and the default statement class.
     * The Host keeps ownership and grants exclusive use on successful return. Remove the
     * connection from circulation, including aliases and other checkout APIs.
     * Local attachment issues no SQL. begin() qualifies the server session.
     *
     * @throws ProtectedTransactionException If local eligibility or the exclusive claim fails. The Host keeps use of the PDO on failure.
     */
    public static function attachExclusive(#[SensitiveParameter] PDO $pdo, ProtectedTransactionCommandCatalog $catalog): self
    {
        if (TransactionConnectionClaim::exists($pdo) || !ProtectedTransactionDriver::isEligible($pdo)) {
            throw new ProtectedTransactionException('The Host connection is ineligible for protected transactions.');
        }

        $checkout = new self($pdo, $catalog);
        TransactionConnectionClaim::acquire($pdo);

        return $checkout;
    }

    /**
     * Qualifies the idle server session under reservation, then starts a write
     * transaction using the Host's isolation settings. Work becomes available
     * after the start is confirmed on the same session. A failed qualification
     * or unconfirmed start settles as DiscardRequired with the corresponding
     * ServerQualificationFailed or StartUnconfirmed reason.
     *
     * @throws ProtectedTransactionException If the checkout is unavailable, the start was already attempted, or the session cannot be confirmed.
     * @throws PDOException The original driver failure, when the driver throws.
     */
    public function begin(): void
    {
        if ($this->session !== null || $this->outcome !== null) {
            throw new ProtectedTransactionException('This protected checkout cannot start a transaction.');
        }
        $this->reserve();
        $reason = Reason::ServerQualificationFailed;
        try {
            $session = $this->inspect(false);
            $reason = Reason::StartUnconfirmed;
            $this->control(ProtectedTransactionDriver::START);
            $this->inspect(true, $session);
            $this->session = $session;
        } catch (Throwable $failure) {
            $this->settle(Disposition::DiscardRequired, $reason);
            throw $failure;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Runs a registered command in the confirmed active transaction. Bindings
     * must match the plan's names exactly. Invalid commands or bindings fail
     * before SQL. Result cursors are released and session continuity is checked
     * before returning. A row-bound failure leaves the checkout active if
     * cleanup and continuity are confirmed. A driver failure or unconfirmed
     * cleanup or continuity fences further work.
     *
     * @param array<array-key, mixed> $bindings Exact names without colons, with string, int, bool or null values. Omit for a parameterless plan.
     * @return int|list<array<array-key, mixed>> Affected-row count or bounded associative rows, as configured by the plan.
     *
     * @throws ProtectedTransactionException For an invalid command, bindings, lifecycle state, row bound or unconfirmed result.
     * @throws PDOException The original driver failure, when the driver throws.
     */
    public function runRegisteredCommand(string $commandId, array $bindings = []): int|array
    {
        $command = $this->catalog->command($commandId);
        $params = $command->bind($bindings);

        return $this->run(static fn(ProtectedTransactionConnection $connection): int|array
            => (new ProtectedTransactionCommandExecutor($connection))->run($command, $params));
    }

    /**
     * Runs a package-owned operation under the shared reservation and seals
     * its driver receiver before releasing the reservation or exposing a result.
     *
     * @template T
     * @param Closure(ProtectedTransactionConnection): T $operation
     * @return T
     */
    private function run(Closure $operation): mixed
    {
        $session = $this->activeSession();
        $this->reserve();
        try {
            $connection = new ProtectedTransactionConnection($this->connection());
            $primary = null;
            try {
                $result = $operation($connection);
                $failure = $connection->failure();
                if ($failure !== null) {
                    throw $failure;
                }

                return $result;
            } catch (Throwable $error) {
                $primary = $error;
                throw $error;
            } finally {
                $this->finishOperation($connection, $session, $primary);
            }
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Releases statement resources before confirming continuity. A latched
     * driver failure skips the session probe. Publish any terminal disposition
     * while still reserved and preserve the primary exception on cleanup failure.
     */
    private function finishOperation(ProtectedTransactionConnection $connection, string $session, ?Throwable $primary): void
    {
        $connection->seal();
        $failure = $connection->failure();
        if ($failure === null && $connection->sentSql()) {
            try {
                $this->inspect(true, $session);
            } catch (Throwable $unconfirmed) {
                $failure = $unconfirmed;
            }
        }
        if ($failure === null) {
            return;
        }
        $info = $failure instanceof PDOException ? $failure->errorInfo : null;
        $lost = $this->mariaDb && ($info[0] ?? null) === 'HY000' && in_array($info[1] ?? null, [1020, '1020'], true);
        $this->settle(Disposition::DiscardRequired, $lost ? Reason::TransactionLost : Reason::OperationUnconfirmed);
        if ($primary === null) {
            throw $failure;
        }
    }

    /**
     * Attempts to commit the active Host transaction once. Confirmed commit
     * and an idle session permit handoff. CommitOutcomeUnknown covers a
     * commit that may have been sent without a confirmed reply.
     * CommitConfirmedConnectionUnsafe preserves a known commit when the
     * subsequent session check fails. Read settlement() after an exception.
     *
     * @throws ProtectedTransactionException|PDOException If the operation is unavailable or completion cannot be confirmed.
     */
    public function commit(): void
    {
        $this->finish(true);
    }

    /**
     * Attempts to roll back the active Host transaction once. Confirmed
     * rollback on the same idle session permits handoff. An unconfirmed end
     * requires discard and preserves the original failure.
     *
     * @throws ProtectedTransactionException|PDOException If the operation is unavailable or completion cannot be confirmed.
     */
    public function rollBack(): void
    {
        $this->finish(false);
    }

    /**
     * Reads the final disposition and its reason without asking the server.
     * Returns null until settlement. The same value remains available after an
     * exception, handoff or discard.
     */
    public function settlement(): ?ProtectedTransactionSettlementDTO
    {
        return $this->outcome;
    }

    /**
     * Hands the original PDO back once after a verified end (Reusable), or
     * cancels an untouched checkout (Unchanged) without qualifying its session.
     * Either handoff ends this checkout's authority and releases the PDO claim.
     *
     * @throws ProtectedTransactionException During a reservation, active transaction, required discard or after handoff or discard.
     */
    public function returnForHost(): PDO
    {
        $this->reserve();
        try {
            if ($this->outcome === null && $this->session === null) {
                $this->settle(Disposition::Unchanged, Reason::CancelledBeforeQualification);
            }
            if ($this->outcome === null || $this->outcome->disposition === Disposition::DiscardRequired) {
                throw new ProtectedTransactionException('The protected connection cannot be returned.');
            }

            $pdo = $this->connection();
            TransactionConnectionClaim::release($pdo);
            $this->pdo = null;

            return $pdo;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Retires the checkout without returning PDO or issuing explicit SQL.
     * Preserves a terminal settlement, or records HostDiscarded when none
     * exists. Dropping the reference may trigger automatic driver rollback.
     *
     * @throws ProtectedTransactionException During a reservation or after handoff or discard.
     */
    public function discardForHost(): void
    {
        $this->reserve();
        try {
            if ($this->outcome === null) {
                $this->settle(Disposition::DiscardRequired, Reason::HostDiscarded);
            }
            // Dropping the last reference can trigger driver-owned cleanup.
            $this->pdo = null;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Confirms the active session before sending an explicit end command and
     * confirms the same idle session before settling. Track the acknowledgement
     * separately from the post-command check so later failure preserves a known commit.
     */
    private function finish(bool $commit): void
    {
        $session = $this->activeSession();
        $this->reserve();
        $reason = Reason::OperationUnconfirmed;
        try {
            $this->inspect(true, $session);
            $reason = $commit ? Reason::CommitOutcomeUnknown : Reason::RollbackUnconfirmed;
            $this->control($commit ? ProtectedTransactionDriver::COMMIT : ProtectedTransactionDriver::ROLLBACK);
            $reason = $commit ? Reason::CommitConfirmedConnectionUnsafe : Reason::RollbackUnconfirmed;
            $this->inspect(false, $session);
            $this->settle(Disposition::Reusable, $commit ? Reason::Committed : Reason::RolledBack);
        } catch (Throwable $failure) {
            $this->settle(Disposition::DiscardRequired, $reason);
            throw $failure;
        } finally {
            $this->busy = false;
        }
    }

    private function reserve(): void
    {
        if ($this->busy || $this->pdo === null) {
            throw new ProtectedTransactionException('The protected checkout is unavailable.');
        }
        $this->busy = true;
    }

    /**
     * Returns the locally qualified session ID only while active authority remains.
     * The caller must still verify the server session and transaction state.
     */
    private function activeSession(): string
    {
        if ($this->session === null || $this->outcome !== null) {
            throw new ProtectedTransactionException('There is no active protected transaction.');
        }

        return $this->session;
    }

    private function connection(): PDO
    {
        return $this->pdo ?? throw new ProtectedTransactionException('The protected connection is unavailable.');
    }

    /**
     * Confirms the expected activity and session. The first qualification
     * accepts the observed identity and records the engine for failure
     * classification. Later checks match the recorded session without
     * reclassifying it.
     */
    private function inspect(bool $active, ?string $expected = null): string
    {
        $session = (new ProtectedTransactionDriver($this->connection()))->inspect($active, $expected);
        if ($expected === null) {
            $this->mariaDb = $session['mariaDb'];
        }

        return $session['id'];
    }

    private function control(string $sql): void
    {
        (new ProtectedTransactionDriver($this->connection()))->control($sql);
    }

    private function settle(Disposition $disposition, Reason $reason): void
    {
        $this->outcome = new ProtectedTransactionSettlementDTO($disposition, $reason);
        $this->session = null;
    }

    private function __clone() {}

    /**
     * Rejects serialization of the exclusive delegation.
     *
     * @return array<array-key, mixed>
     *
     * @throws ProtectedTransactionException Always.
     */
    public function __serialize(): array
    {
        throw new ProtectedTransactionException('A protected checkout cannot be serialized.');
    }

    /**
     * Rejects restoring a delegation outside the exclusive attachment path.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws ProtectedTransactionException Always.
     */
    public function __unserialize(array $data): void
    {
        throw new ProtectedTransactionException('A protected checkout cannot be restored.');
    }
}
