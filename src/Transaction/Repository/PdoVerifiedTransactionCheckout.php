<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Closure;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\DTO\VerifiedTransactionDecisionDTO;
use Maatify\PhpPersistence\Transaction\DTO\VerifiedTransactionSettlementDTO;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionOutcomeEnum as Outcome;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

/**
 * Holds one delegated PDO for one verified transaction. Use runOwned() for a
 * checkout-managed start and end, or beginHost() and runInHost() for a single
 * savepoint operation whose outer end remains a Host decision.
 *
 * The Host supplies an idle and exclusively held session with trusted,
 * transaction-preserving commands. The checkout's continuity checks rely on
 * the Host preventing raw aliases, reconnect and concurrent use.
 *
 * Normal payloads require acknowledged control and confirmed continuity.
 * A latched failure fences further SQL and handoff. The first safety failure
 * governs settlement and an escaping callback throwable keeps its identity.
 * Decide handoff or retirement from settlement(). Callbacks are never replayed.
 */
final class PdoVerifiedTransactionCheckout
{
    /** Qualified session ID while the transaction is held. Terminal settlement clears it. */
    private ?string $session = null;
    private bool $mariaDb = false;
    private bool $busy = false;
    /** The one permitted operation has been attempted, including a failed attempt. */
    private bool $attempted = false;
    private VerifiedTransactionSettlementDTO $snapshot;

    private function __construct(
        #[SensitiveParameter]
        private ?PDO $pdo,
        private readonly ProtectedTransactionCommandCatalog $catalog,
    ) {
        $this->snapshot = new VerifiedTransactionSettlementDTO(null, null, null, null, null);
    }

    /**
     * Claims an idle, non-persistent pdo_mysql connection without issuing SQL.
     * The Host configures native prepares, exception mode, the default statement
     * class, disabled multi-statements and enabled autocommit. The first start
     * qualifies the session. Supply trusted plans that preserve the transaction.
     *
     * @throws ProtectedTransactionException The connection is already claimed or fails local eligibility. The Host retains authority on refusal.
     */
    public static function attachExclusive(
        #[SensitiveParameter]
        PDO $pdo,
        ProtectedTransactionCommandCatalog $catalog,
    ): self {
        if (TransactionConnectionClaim::exists($pdo)
            || !ProtectedTransactionDriver::isEligible($pdo)
            || $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)
            || $pdo->inTransaction()
        ) {
            throw new ProtectedTransactionException('The Host connection is ineligible for verified transactions.');
        }

        $checkout = new self($pdo, $catalog);
        TransactionConnectionClaim::acquire($pdo);

        return $checkout;
    }

    /**
     * Starts the single Owned transaction from an unused checkout. Apply
     * commits and Discard rolls back. The normal payload is returned after
     * acknowledgement of that end and confirmation of the same idle session.
     *
     * @param Closure(VerifiedTransactionCommandsInterface): VerifiedTransactionDecisionDTO $operation
     */
    public function runOwned(Closure $operation): mixed
    {
        $this->requireIdle();

        return $this->run($operation, owned: true);
    }

    /**
     * Starts the Host transaction from an unused checkout and confirms the
     * qualified session before admitting work. A start that fails after
     * reservation publishes a terminal settlement requiring retirement.
     */
    public function beginHost(): void
    {
        $this->requireIdle();
        $this->reserve();
        $reason = Reason::QUALIFICATION_FAILED;
        $transaction = Outcome::NOT_STARTED;
        try {
            $this->start($reason, $transaction);
        } catch (Throwable $failure) {
            $this->settleEnd($transaction, $reason, Disposition::DISCARD_REQUIRED);
            throw $failure;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Runs a registered command before or after the Host operation window.
     * Requires an active Host transaction and confirms session continuity
     * and cursor release before returning the result. An invalid command ID
     * or binding is rejected before SQL. A latched failure fences the checkout.
     *
     * @param array<array-key, mixed> $bindings Exact names without colons, with scalar or null values.
     * @return int|list<array<array-key, mixed>>
     */
    public function runRegisteredCommand(string $commandId, array $bindings = []): int|array
    {
        $this->requireActive();
        $plan = $this->catalog->command($commandId);
        $params = $plan->bind($bindings);
        $this->reserve();
        $receiver = new ProtectedTransactionConnection($this->connection());
        try {
            $receiver->verifyActive($this->session);
            $primary = null;
            try {
                $result = (new ProtectedTransactionCommandExecutor($receiver))->run($plan, $params, releaseBeforeReturn: true);
            } catch (Throwable $failure) {
                $primary = $failure;
                throw $failure;
            } finally {
                if ($receiver->failure() === null) {
                    try {
                        $receiver->verifyActive($this->session);
                    } catch (Throwable $failure) {
                        if ($primary === null) {
                            throw $failure;
                        }
                    }
                }
            }

            return $result;
        } catch (Throwable $failure) {
            if ($receiver->failure() !== null) {
                $this->settleEnd(Outcome::UNKNOWN, $this->receiverReason($receiver), Disposition::DISCARD_REQUIRED);
            }
            throw $failure;
        } finally {
            $receiver->seal();
            $this->busy = false;
        }
    }

    /**
     * Runs the only savepoint-local operation in an active Host transaction.
     * Apply requires acknowledged release. Discard requires acknowledged
     * rollback to the savepoint and release. Both require continuity before
     * returning the payload. The Host still decides the outer end.
     *
     * @param Closure(VerifiedTransactionCommandsInterface): VerifiedTransactionDecisionDTO $operation
     */
    public function runInHost(Closure $operation): mixed
    {
        $this->requireActive();
        if ($this->attempted) {
            throw new ProtectedTransactionException('The verified operation was already attempted.');
        }

        return $this->run($operation, owned: false);
    }

    /**
     * Commits the active Host transaction. An acknowledged commit is recorded
     * even when the final idle-session check requires connection retirement.
     */
    public function commit(): void
    {
        $this->finishHost(commit: true);
    }

    /**
     * Rolls back the active Host transaction. An acknowledged rollback is
     * recorded even when the final idle-session check fails.
     */
    public function rollBack(): void
    {
        $this->finishHost(commit: false);
    }

    /**
     * Reads the last published local snapshot without I/O. Its fields are null
     * on attachment. Previously returned snapshots remain unchanged.
     */
    public function settlement(): VerifiedTransactionSettlementDTO
    {
        return $this->snapshot;
    }

    /**
     * Returns the same PDO once and releases its claim after confirmed
     * completion or untouched cancellation. Cancellation needs no server probe.
     *
     * @throws ProtectedTransactionException The checkout is busy, already handed off, retired, still active or requires discard.
     */
    public function returnForHost(): PDO
    {
        $this->reserve();
        try {
            if ($this->snapshot->disposition === null && $this->session === null) {
                $this->settleEnd(Outcome::NOT_STARTED, null, Disposition::UNCHANGED);
            }
            if ($this->snapshot->disposition === null || $this->snapshot->disposition === Disposition::DISCARD_REQUIRED) {
                throw new ProtectedTransactionException('The verified connection cannot be returned.');
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
     * Retires the delegation by dropping the PDO reference. The claim remains
     * while the object lives. This issues no explicit database command and
     * preserves previously recorded terminal evidence.
     */
    public function discardForHost(): void
    {
        $this->reserve();
        try {
            if ($this->snapshot->disposition === null) {
                $this->settleEnd(
                    $this->session === null ? Outcome::NOT_STARTED : Outcome::UNKNOWN,
                    Reason::HOST_DISCARDED,
                    Disposition::DISCARD_REQUIRED,
                );
            }
            $this->pdo = null;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Reserves the checkout through callback expiry and settlement. A latched
     * safety failure suppresses the decision before transaction control. An
     * escaping callback throwable has precedence even if cleanup fails.
     *
     * @param Closure(VerifiedTransactionCommandsInterface): mixed $operation
     */
    private function run(Closure $operation, bool $owned): mixed
    {
        $this->reserve();
        $this->attempted = true;
        $receiver = new ProtectedTransactionConnection($this->connection());
        $reason = Reason::CONTINUITY_UNCONFIRMED;
        $transaction = $owned ? Outcome::NOT_STARTED : Outcome::UNKNOWN;
        $effect = Outcome::NOT_STARTED;
        $primary = null;
        $payload = null;
        $published = false;
        try {
            $savepoint = null;
            if ($owned) {
                $this->start($reason, $transaction);
            } else {
                $this->inspect(true);
                $reason = Reason::SAVEPOINT_UNCONFIRMED;
                $savepoint = SavepointScope::open($receiver);
                $reason = Reason::CONTINUITY_UNCONFIRMED;
                $this->inspect(true);
            }

            $effect = Outcome::UNKNOWN;
            // Keep execution handles out of arguments exposed by exceptions inside the callback.
            $executor = new ProtectedTransactionCommandExecutor($receiver);
            $scope = new VerifiedTransactionCommandScope(
                function (string $commandId, array $bindings) use ($receiver, $executor): int|array {
                    $receiver->ensureAvailable();
                    $plan = $this->catalog->command($commandId);
                    $params = $plan->bind($bindings);
                    try {
                        return $executor->run($plan, $params, releaseBeforeReturn: true);
                    } catch (Throwable $primary) {
                        if ($receiver->failure() === null) {
                            try {
                                $receiver->verifyActive($this->session);
                            } catch (Throwable) {
                                // Keep the command failure visible; the probe is latched.
                            }
                        }
                        throw $primary;
                    }
                },
            );
            $decision = null;
            try {
                $decision = $operation($scope);
            } catch (Throwable $failure) {
                $primary = $failure;
            } finally {
                $scope->close();
            }

            if (($latched = $receiver->failure()) !== null) {
                $reason = $this->receiverReason($receiver);
                throw $latched;
            }
            $commit = false;
            if ($primary === null) {
                if ($decision instanceof VerifiedTransactionDecisionDTO) {
                    $commit = $decision->isApply;
                    $payload = $decision->payload;
                } else {
                    $primary = new ProtectedTransactionException('A verified operation must return a decision.');
                }
            }
            if ($savepoint === null) {
                $this->end($commit, $reason, $transaction);
                $effect = $transaction;
            } else {
                $this->finishWindow($savepoint, $commit, $reason, $effect);
            }

            $this->snapshot = new VerifiedTransactionSettlementDTO(
                $effect,
                $primary === null ? null : Reason::CALLBACK_FAILED,
                $owned ? $transaction : null,
                null,
                $owned ? Disposition::REUSABLE : null,
            );
            $published = true;
            if ($owned) {
                $this->session = null;
            }
        } catch (Throwable $failure) {
            if ($owned && ($transaction === Outcome::COMMITTED || $transaction === Outcome::ROLLED_BACK)) {
                $effect = $transaction;
            }
            $reason = $this->continuityReason($reason, $failure);
            $this->snapshot = new VerifiedTransactionSettlementDTO($effect, $reason, $transaction, $reason, Disposition::DISCARD_REQUIRED);
            $published = true;
            $this->session = null;
            throw $primary ?? $failure;
        } finally {
            $receiver->seal();
            if (!$published) {
                // A suspended Fiber can unwind without returning or throwing to the call site.
                $this->snapshot = new VerifiedTransactionSettlementDTO(
                    $effect,
                    Reason::CONTINUITY_UNCONFIRMED,
                    $transaction,
                    Reason::CONTINUITY_UNCONFIRMED,
                    Disposition::DISCARD_REQUIRED,
                );
                $this->session = null;
            }
            $this->busy = false;
        }

        if ($primary !== null) {
            throw $primary;
        }

        return $payload;
    }

    /**
     * Qualifies an idle session, then records start uncertainty before START.
     * Publishes the qualified session only after confirming it is active.
     */
    private function start(Reason &$reason, Outcome &$transaction): void
    {
        $reason = Reason::QUALIFICATION_FAILED;
        $driver = new ProtectedTransactionDriver($this->connection());
        $session = $driver->inspect(false, null, closeOnFetchFailure: true);
        $reason = Reason::START_UNCONFIRMED;
        $transaction = Outcome::UNKNOWN;
        $driver->start();
        $driver->inspect(true, $session['id'], closeOnFetchFailure: true);
        $this->session = $session['id'];
        $this->mariaDb = $session['mariaDb'];
    }

    /**
     * Records acknowledged local undo before RELEASE so a later release or
     * continuity failure preserves that evidence while suppressing the payload.
     */
    private function finishWindow(SavepointScope $savepoint, bool $apply, Reason &$reason, Outcome &$effect): void
    {
        $reason = Reason::CONTINUITY_UNCONFIRMED;
        $this->inspect(true);
        if (!$apply) {
            $reason = Reason::ROLLBACK_TO_UNCONFIRMED;
            $savepoint->rollbackStrict();
            $effect = Outcome::LOCAL_UNDONE;
        }
        $reason = Reason::RELEASE_UNCONFIRMED;
        $savepoint->releaseStrict();
        $reason = Reason::CONTINUITY_UNCONFIRMED;
        $this->inspect(true);
        if ($apply) {
            $effect = Outcome::PENDING_HOST;
        }
    }

    private function finishHost(bool $commit): void
    {
        $this->requireActive();
        $this->reserve();
        $reason = Reason::CONTINUITY_UNCONFIRMED;
        $transaction = Outcome::UNKNOWN;
        try {
            $this->end($commit, $reason, $transaction);
            $this->settleEnd($transaction, null, Disposition::REUSABLE);
        } catch (Throwable $failure) {
            $this->settleEnd($transaction, $this->continuityReason($reason, $failure), Disposition::DISCARD_REQUIRED);
            throw $failure;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Records the acknowledged whole-transaction end before checking whether
     * the same idle session is safe to return. An unacknowledged end stays unknown.
     */
    private function end(bool $commit, Reason &$reason, Outcome &$transaction): void
    {
        $reason = Reason::CONTINUITY_UNCONFIRMED;
        $this->inspect(true);
        $reason = $commit ? Reason::COMMIT_OUTCOME_UNKNOWN : Reason::ROLLBACK_UNCONFIRMED;
        (new ProtectedTransactionDriver($this->connection()))->end($commit);
        $transaction = $commit ? Outcome::COMMITTED : Outcome::ROLLED_BACK;
        $reason = Reason::HANDOFF_UNCONFIRMED;
        $this->inspect(false);
    }

    private function inspect(bool $active): void
    {
        (new ProtectedTransactionDriver($this->connection()))->inspect(
            $active,
            $this->session ?? throw new ProtectedTransactionException('There is no verified session.'),
            closeOnFetchFailure: true,
        );
    }

    /** Classifies the first latched failure, with recognized transaction loss taking precedence. */
    private function receiverReason(ProtectedTransactionConnection $receiver): Reason
    {
        if ($this->isTransactionLoss($receiver->failure())) {
            return Reason::TRANSACTION_LOST;
        }
        if ($receiver->cursorFailure()) {
            return Reason::CURSOR_UNCONFIRMED;
        }

        return $receiver->continuityFailure() ? Reason::CONTINUITY_UNCONFIRMED : Reason::COMMAND_FAILED;
    }

    private function continuityReason(Reason $reason, Throwable $failure): Reason
    {
        return $reason === Reason::CONTINUITY_UNCONFIRMED && $this->isTransactionLoss($failure)
            ? Reason::TRANSACTION_LOST
            : $reason;
    }

    /** Matches the engine-specific driver signals used to classify loss of transaction authority. */
    private function isTransactionLoss(?Throwable $failure): bool
    {
        $info = $failure instanceof PDOException ? $failure->errorInfo : null;
        $state = $info[0] ?? null;
        $code = $info[1] ?? null;

        return ($state === '40001' && ($code === 1213 || $code === '1213'))
            || ($this->mariaDb && $state === 'HY000' && ($code === 1020 || $code === '1020'));
    }

    /** Publishes the terminal checkout result while keeping the operation evidence intact. */
    private function settleEnd(Outcome $outcome, ?Reason $reason, Disposition $disposition): void
    {
        $this->snapshot = new VerifiedTransactionSettlementDTO(
            $this->snapshot->operationOutcome,
            $this->snapshot->operationReason,
            $outcome,
            $reason,
            $disposition,
        );
        $this->session = null;
    }

    private function requireIdle(): void
    {
        $this->requireAvailable();
        if ($this->session !== null || $this->snapshot->disposition !== null) {
            throw new ProtectedTransactionException('This verified checkout cannot start a transaction.');
        }
    }

    private function requireActive(): void
    {
        $this->requireAvailable();
        if ($this->session === null || $this->snapshot->disposition !== null) {
            throw new ProtectedTransactionException('There is no active verified Host transaction.');
        }
    }

    private function requireAvailable(): void
    {
        if ($this->busy || $this->pdo === null) {
            throw new ProtectedTransactionException('The verified checkout is unavailable.');
        }
    }

    private function reserve(): void
    {
        $this->requireAvailable();
        $this->busy = true;
    }

    private function connection(): PDO
    {
        return $this->pdo ?? throw new ProtectedTransactionException('The verified checkout is unavailable.');
    }

    private function __clone() {}

    /**
     * Rejects serialization of the checkout and its connection authority.
     *
     * @return array<array-key, mixed>
     * @throws ProtectedTransactionException Always.
     */
    public function __serialize(): array
    {
        throw new ProtectedTransactionException('A verified checkout cannot be serialized.');
    }

    /**
     * Serialized data cannot restore connection authority.
     *
     * @param array<array-key, mixed> $data
     * @throws ProtectedTransactionException Always.
     */
    public function __unserialize(array $data): void
    {
        throw new ProtectedTransactionException('A verified checkout cannot be restored.');
    }
}
