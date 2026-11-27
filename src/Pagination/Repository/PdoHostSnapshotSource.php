<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotBindings;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPageAttempt;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPageGrant;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\HostSnapshotPageSettlementDTO;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotAccessModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum as State;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandEffectEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandResultModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageAttemptStateEnum as Stage;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageDispositionEnum as Disposition;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum as Reason;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotRetirementDispositionEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotRowLimitExceededException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use PDO;
use PDOException;
use PDOStatement;
use stdClass;
use Pdo\Mysql;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * Holds exclusive, delegated use of one Host-provided PDO for snapshot pagination.
 * The Host keeps the source alive, routes all connection use through it without
 * aliases, reconnect, replay, or multiplexing, and directs transaction completion
 * and the final disposition of the connection.
 *
 * At most one checkout is registered at a time. Page grants belong to a qualified
 * REPEATABLE READ transaction generation. Commands and admitted page attempts
 * share a reservation so they cannot control the same session concurrently.
 */
final class PdoHostSnapshotSource
{
    /**
     * Prevents attaching the same live PDO a second time, including after handoff.
     *
     * @var WeakMap<PDO, true>|null
     */
    private static ?WeakMap $attached = null;

    /** @var WeakReference<PdoHostSnapshotCheckout>|null */
    private ?WeakReference $checkout = null;
    /** @var WeakReference<PdoHostSnapshotCheckout>|null */
    private ?WeakReference $retiredCheckout = null;
    /** A source-local identity shared with grants without retaining the checkout handle. */
    private ?object $checkoutIdentity = null;
    /** @var WeakMap<HostSnapshotPageGrant, HostSnapshotGrantRegistration> */
    private WeakMap $grants;
    /** @var WeakMap<HostSnapshotPageAttempt, HostSnapshotAttemptRecord> */
    private WeakMap $attempts;
    private State $state = State::IDLE;
    private bool $busy = false;
    private ?string $sessionId = null;
    /** Identifies the qualified transaction against which the source checks page grants. */
    private ?object $generation = null;
    // Session variables do not reveal a pending one-transaction override.
    /** Keeps the next-transaction setting unresolved until the source confirms its own begin. */
    private bool $nextTransactionUnconfirmed = true;

    private function __construct(
        private HostSnapshotConnection $connection,
        private readonly HostSnapshotCatalog $catalog,
    ) {
        $this->grants = new WeakMap();
        $this->attempts = new WeakMap();
    }

    /**
     * Registers one exclusively delegated, non-persistent pdo_mysql PDO or
     * Pdo\Mysql with exception mode and the default PDOStatement class. Rejects
     * a PDO already registered and leaves attributes under Host control. The
     * idle-session check occurs when the Host requests a checkout.
     */
    public static function attachExclusive(PDO $pdo, HostSnapshotCatalog $catalog): self
    {
        self::$attached ??= new WeakMap();
        if (isset(self::$attached[$pdo])) {
            throw new HostSnapshotPaginationException('This PDO already has a Host snapshot source.');
        }
        if (($pdo::class !== PDO::class && $pdo::class !== Mysql::class)
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_PERSISTENT)
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== [PDOStatement::class]
        ) {
            throw new HostSnapshotPaginationException('The Host connection is ineligible for snapshot pagination.');
        }

        self::$attached[$pdo] = true;

        return new self(new HostSnapshotConnection($pdo), $catalog);
    }

    /**
     * Issues the only active checkout after confirming a stable, idle session
     * with autocommit enabled. A failed session check quarantines the source.
     * The checkout still needs a qualified transaction before authorizing pages.
     */
    public function checkout(): PdoHostSnapshotCheckout
    {
        if ($this->busy || $this->checkout !== null || $this->state !== State::IDLE) {
            throw new HostSnapshotPaginationException('The Host snapshot source is unavailable.');
        }
        $this->busy = true;
        try {
            $this->sessionId = $this->confirmSession(false);
            $checkout = PdoHostSnapshotCheckout::issue($this);
            $this->checkout = WeakReference::create($checkout);
            $this->checkoutIdentity = new stdClass();

            return $checkout;
        } catch (Throwable $error) {
            $this->state = State::QUARANTINED;
            throw $error;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Permanently retires the source and transfers the same PDO when statement
     * release is confirmed. An idle disposition requires a known idle session
     * and a resolved next-transaction setting. Otherwise the Host receives the
     * PDO for reconciliation. A held reservation or unresolved cursor blocks transfer.
     */
    public function retireForHost(): HostSnapshotRetirement
    {
        $this->reserveRetirement();
        try {
            if (! $this->connection->resourcesReleased()) {
                $this->quarantine();
                throw new HostSnapshotPaginationException('Host snapshot resources still prevent a handoff.');
            }
            $disposition = $this->state === State::IDLE && $this->sessionId !== null && ! $this->nextTransactionUnconfirmed
                ? HostSnapshotRetirementDispositionEnum::RETURNED_IDLE
                : HostSnapshotRetirementDispositionEnum::RETURNED_FOR_RECONCILIATION;
            $handoff = $this->connection->handoff($disposition);
            $this->markRetired();
            $this->connection->detach();

            return $handoff;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Permanently retires the source and drops the library's driver references.
     * A held reservation rejects the request. This issues no explicit SQL or
     * transaction command, and dropping the last reference can trigger driver I/O,
     * rollback or disconnection. It gives no acknowledgement of server outcome.
     */
    public function discardForHost(): void
    {
        $this->reserveRetirement();
        try {
            $this->markRetired();
            $this->connection->detach();
        } finally {
            $this->busy = false;
        }
    }

    /** Takes exclusive use for retirement, including when the source is quarantined. */
    private function reserveRetirement(): void
    {
        if ($this->busy || $this->state === State::RETIRED) {
            throw new HostSnapshotPaginationException('The Host snapshot source cannot be retired.');
        }
        $this->busy = true;
    }

    /**
     * Invalidates checkout and generation authority while preserving the retired
     * state for the previous checkout handle.
     */
    private function markRetired(): void
    {
        $this->retiredCheckout = $this->checkout;
        $this->checkout = null;
        $this->checkoutIdentity = null;
        $this->generation = null;
        $this->state = State::RETIRED;
    }

    /** @internal Reports BUSY under reservation and distinguishes release from retirement. */
    public function checkoutState(PdoHostSnapshotCheckout $checkout): State
    {
        if ($this->retiredCheckout?->get() === $checkout) {
            return State::RETIRED;
        }
        if ($this->checkout?->get() !== $checkout) {
            return State::RELEASED;
        }

        return $this->busy ? State::BUSY : $this->state;
    }

    /**
     * @internal Releases only the registered idle checkout after confirming the
     * same session and that no next-transaction setting remains unresolved. The
     * source retains the PDO, and a failed confirmation quarantines it.
     */
    public function releaseCheckout(PdoHostSnapshotCheckout $checkout): void
    {
        $this->reserve($checkout, State::IDLE);
        try {
            if ($this->nextTransactionUnconfirmed) {
                throw new HostSnapshotPaginationException('The next Host snapshot transaction setting is unresolved.');
            }
            $this->confirmSession(false);
            $this->checkout = null;
            $this->checkoutIdentity = null;
        } catch (Throwable $error) {
            $this->state = State::QUARANTINED;
            throw $error;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @internal Sets REPEATABLE READ for the next transaction and starts it with
     * the requested mode on the idle checkout. Records a new generation after
     * confirming the same active session. Uncertain start quarantines the source.
     */
    public function beginCheckout(PdoHostSnapshotCheckout $checkout, HostSnapshotAccessModeEnum $mode): void
    {
        $this->reserve($checkout, State::IDLE);
        try {
            $this->confirmSession(false);
            $this->connection->control('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connection->control('START TRANSACTION ' . $mode->value);
            $this->confirmSession(true);
            $this->nextTransactionUnconfirmed = false;
            $this->generation = new stdClass();
            $this->state = State::TRANSACTION_ACTIVE;
        } catch (Throwable $error) {
            $this->quarantine();
            throw $error;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Runs a registered plan under the shared reservation. An invalidating
     * command retires the page generation before SQL. A recognized result
     * overflow remains the reported error even if scope confirmation fails
     * and quarantines the source.
     *
     * @internal
     * @param array<array-key, mixed> $bindings
     * @return int|list<array<array-key, mixed>>
     */
    public function executeCheckoutCommand(PdoHostSnapshotCheckout $checkout, string $id, array $bindings): int|array
    {
        $this->reserve($checkout, State::IDLE, State::TRANSACTION_ACTIVE, State::TRANSACTION_UNQUALIFIED);
        try {
            $command = $this->catalog->command($id);
            $params = HostSnapshotBindings::exact($command->parameterNames, $bindings);
            $preserves = $command->effect === HostSnapshotCommandEffectEnum::PRESERVES_TRANSACTION;
            $active = $this->state !== State::IDLE;
            if (! $preserves) {
                $this->generation = null;
                // A trusted, invalidating single statement may also set a next-transaction override.
                $this->nextTransactionUnconfirmed = true;
            }
            try {
                $maxRows = $command->resultMode === HostSnapshotCommandResultModeEnum::BOUNDED_ROWS ? $command->maxRows : null;
                try {
                    $result = $this->connection->execute($command->sql, $params, $maxRows);
                } catch (HostSnapshotRowLimitExceededException) {
                    $result = new HostSnapshotPaginationException('The Host snapshot result exceeds its row limit.');
                }
                $this->confirmSession($preserves ? $active : null);
                if (! $preserves) {
                    $this->state = $this->connection->transactionActive() ? State::TRANSACTION_UNQUALIFIED : State::QUARANTINED;
                }

            } catch (Throwable $error) {
                $this->quarantine();
                throw isset($result) && $result instanceof HostSnapshotPaginationException ? $result : $error;
            }
            if ($result instanceof HostSnapshotPaginationException) {
                throw $result;
            }

            return $result;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @internal Invalidates the generation before explicit commit or rollback.
     * Completion requires the same session to become idle without chaining or
     * releasing it. Unconfirmed continuity or a pending setting can quarantine it.
     */
    public function finishCheckout(PdoHostSnapshotCheckout $checkout, bool $commit): void
    {
        $this->reserve($checkout, State::TRANSACTION_ACTIVE, State::TRANSACTION_UNQUALIFIED);
        $this->generation = null;
        try {
            $this->confirmSession(true);
            $this->connection->control($commit ? 'COMMIT AND NO CHAIN NO RELEASE' : 'ROLLBACK AND NO CHAIN NO RELEASE');
            $this->confirmSession(false);
            $this->state = $this->nextTransactionUnconfirmed ? State::QUARANTINED : State::IDLE;
        } catch (Throwable $error) {
            $this->quarantine();
            throw $error;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Registers the query, exact bindings and request against the current checkout
     * and qualified generation without reading a page. Rechecks both identities
     * after constructing the registration because autoload may run Host code.
     *
     * @internal
     * @param array<array-key, mixed> $bindings
     */
    public function authorizeCheckoutPage(
        PdoHostSnapshotCheckout $checkout,
        string $queryId,
        array $bindings,
        PageRequest $request,
    ): HostSnapshotPageGrant {
        if ($this->busy || $this->checkout?->get() !== $checkout || $this->checkoutIdentity === null
            || $this->state !== State::TRANSACTION_ACTIVE || $this->generation === null
        ) {
            throw new HostSnapshotPaginationException('The Host snapshot transaction cannot authorize a page.');
        }
        $checkoutIdentity = $this->checkoutIdentity;
        $generation = $this->generation;
        $plan = $this->catalog->page($queryId);
        $grant = HostSnapshotPageGrant::issue();
        $registration = new HostSnapshotGrantRegistration(
            $checkoutIdentity,
            $generation,
            $plan,
            $plan->descriptor($bindings),
            $request,
        );
        // Class loading during registration can reenter a Host command.
        if (! $this->canRegisterOn($checkoutIdentity, $generation)) {
            throw new HostSnapshotPaginationException('The Host snapshot transaction changed during authorization.');
        }
        $this->grants[$grant] = $registration;

        return $grant;
    }

    /** Confirms that the captured grant context still holds after possible Host reentry. */
    private function canRegisterOn(object $checkoutIdentity, object $generation): bool
    {
        return ! $this->busy && $this->checkoutIdentity === $checkoutIdentity && $this->generation === $generation;
    }

    /**
     * @internal Creates a fresh invocation and its outcome record for a grant from
     * this checkout. The grant's generation and consumption are checked on admission.
     */
    public function newCheckoutAttempt(PdoHostSnapshotCheckout $checkout, HostSnapshotPageGrant $grant): HostSnapshotPageAttempt
    {
        $registered = $this->grants[$grant] ?? null;
        if ($registered === null || $this->checkout?->get() !== $checkout
            || $registered->checkoutIdentity !== $this->checkoutIdentity
        ) {
            throw new HostSnapshotPaginationException('The Host snapshot grant does not belong to this checkout.');
        }
        $record = new HostSnapshotAttemptRecord($grant);
        $attempt = HostSnapshotPageAttempt::issue(WeakReference::create($this), $record);
        $this->attempts[$attempt] = $record;

        return $attempt;
    }

    /**
     * Settles a registered fresh attempt. Admission alone consumes the grant.
     * An admitted read publishes a terminal outcome before releasing the shared
     * reservation, and uncertainty quarantines the source. A primary read or
     * mapper throwable survives subsequent confirmation and cleanup.
     *
     * @internal
     * @return PageResultDTO<array<array-key, mixed>|object>
     */
    public function readAttempt(HostSnapshotPageAttempt $attempt): PageResultDTO
    {
        // First-use autoloading may run Host code. Materialize the outcomes before
        // checking the generation, then admit without anything that invokes a callback.
        $fallback = new HostSnapshotPageSettlementDTO(Disposition::HOST_ACTION_REQUIRED, Reason::SETTLEMENT_UNCONFIRMED);
        $scopeUnconfirmed = new HostSnapshotPageSettlementDTO(Disposition::HOST_ACTION_REQUIRED, Reason::SCOPE_UNCONFIRMED);
        $resourcesUnconfirmed = new HostSnapshotPageSettlementDTO(Disposition::HOST_ACTION_REQUIRED, Reason::RESOURCE_RELEASE_UNCONFIRMED);

        $record = $this->attempts[$attempt] ?? null;
        if ($record === null || $record->state !== Stage::NEW || $record->grant === null) {
            throw new HostSnapshotPaginationException('The Host snapshot attempt is unavailable.');
        }
        $grant = $this->grants[$record->grant];
        $rejected = match (true) {
            $this->checkout?->get() === null || $grant->checkoutIdentity !== $this->checkoutIdentity
                || $this->state === State::QUARANTINED || $this->state === State::RETIRED => Reason::SOURCE_UNAVAILABLE,
            $this->generation !== $grant->generation => Reason::STALE_CONTEXT,
            $grant->consumed => Reason::GRANT_CONSUMED,
            $this->busy => Reason::BUSY,
            default => null,
        };
        if ($rejected !== null) {
            $record->finish(new HostSnapshotPageSettlementDTO(Disposition::NOT_ACCEPTED, $rejected));
            throw new HostSnapshotPaginationException('The Host snapshot attempt was not accepted.');
        }

        $this->busy = true;
        $grant->consumed = true;
        $record->state = Stage::RUNNING;
        $settlement = $scopeUnconfirmed;
        $primary = null;
        $mapping = false;
        try {
            $this->confirmSession(true);
            try {
                $page = (new HostSnapshotPageReader($this->connection))->read($grant, $mapping);
            } catch (Throwable $error) {
                $primary = $error;
                // In an eligible side-effect-free read, cardinality, data, and
                // syntax/access-rule failures affect the statement. Other classes
                // include transaction or driver ambiguity; the status bit alone
                // cannot establish transaction continuity for those failures.
                $sqlState = $error instanceof PDOException ? ($error->errorInfo[0] ?? null) : null;
                $knownReadError = $error instanceof PaginationExecutionException
                    || (is_string($sqlState) && preg_match('/\A(?:21|22|42)[A-Z0-9]{3}\z/', $sqlState) === 1);
                if (! $this->connection->resourcesReleased() || (! $mapping && ! $knownReadError)) {
                    throw $error;
                }
            }
            $this->confirmSession(true);
            $settlement = $fallback;
            $settlement = new HostSnapshotPageSettlementDTO(
                Disposition::RETURNED_TO_HOST,
                $primary === null ? Reason::PAGE_COMPLETED : Reason::READ_FAILED,
            );
            if ($primary !== null) {
                throw $primary;
            }

            return $page;
        } catch (Throwable $error) {
            throw $primary ?? $error;
        } finally {
            if (! $this->connection->resourcesReleased()) {
                $settlement = $resourcesUnconfirmed;
            }
            if ($settlement->disposition === Disposition::HOST_ACTION_REQUIRED) {
                $this->quarantine();
            }
            $record->finish($settlement);
            $this->busy = false;
        }
    }

    /** Revokes the generation and prevents further work until the Host disposes of the source. */
    private function quarantine(): void
    {
        $this->generation = null;
        $this->state = State::QUARANTINED;
    }

    /** Takes the shared reservation only for the registered checkout in an allowed state. */
    private function reserve(PdoHostSnapshotCheckout $checkout, State ...$allowed): void
    {
        if ($this->busy || $this->checkout?->get() !== $checkout || ! in_array($this->state, $allowed, true)) {
            throw new HostSnapshotPaginationException('The Host snapshot checkout is unavailable.');
        }
        $this->busy = true;
    }

    /**
     * Checks the recorded session identity and enabled autocommit. A null
     * expectation skips the activity check. These observations cannot establish
     * continuity after a command or failure that may have replaced the transaction.
     */
    private function confirmSession(?bool $active): string
    {
        $observed = $this->connection->inspect();
        if (($this->sessionId !== null && $this->sessionId !== $observed['session'])
            || ! $observed['autocommit'] || ($active !== null && $active !== $observed['active'])
        ) {
            throw new HostSnapshotPaginationException('Unable to confirm the Host snapshot session.');
        }

        return $observed['session'];
    }

    private function __clone() {}

    /**
     * @return array<array-key, mixed>
     *
     * @throws HostSnapshotPaginationException Always. The source owns an in-process Host connection registration.
     */
    public function __serialize(): array
    {
        throw new HostSnapshotPaginationException('A Host snapshot source cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws HostSnapshotPaginationException Always. The source must establish exclusive use through live attachment.
     */
    public function __unserialize(array $data): void
    {
        throw new HostSnapshotPaginationException('A Host snapshot source cannot be restored.');
    }
}
