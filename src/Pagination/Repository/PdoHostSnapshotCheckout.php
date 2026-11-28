<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPageAttempt;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPageGrant;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotAccessModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;

/**
 * The source-issued handle for one delegated PDO session. The Host controls
 * transaction start and end with this handle. Releasing it ends its authority
 * while exclusive connection use stays with the source until retirement or discard.
 */
final readonly class PdoHostSnapshotCheckout
{
    private function __construct(private PdoHostSnapshotSource $source) {}

    /** @internal Only the checkout registered by the source has authority. */
    public static function issue(PdoHostSnapshotSource $source): self
    {
        return new self($source);
    }

    /** Reports current authority and availability, including after release or retirement. */
    public function state(): HostSnapshotCheckoutStateEnum
    {
        return $this->source->checkoutState($this);
    }

    /**
     * Starts and confirms a new REPEATABLE READ generation on an idle checkout.
     * The access mode defaults to ReadWrite. Isolation applies to this
     * transaction, leaving session defaults under Host control. An unavailable
     * checkout is rejected before reservation. A session check or start failure
     * after reservation quarantines the source and propagates the failure.
     */
    public function beginRepeatableRead(HostSnapshotAccessModeEnum $mode = HostSnapshotAccessModeEnum::ReadWrite): void
    {
        $this->source->beginCheckout($this, $mode);
    }

    /**
     * Runs one trusted catalog command with the exact declared bindings. The
     * plan selects an affected-row count or bounded associative rows with their
     * driver keys. The default effect invalidates existing page grants before
     * execution and leaves next-transaction characteristics unconfirmed.
     * After session confirmation, an invalidating command leaves the checkout
     * TransactionUnqualified if PDO reports an active transaction, or
     * Quarantined if PDO reports idle. An uncertain session or transaction
     * effect can also quarantine the source. The Host can explicitly end the
     * unqualified transaction. A quarantined checkout rejects release().
     * The Host can discard the source or call PdoHostSnapshotSource::retireForHost(),
     * which returns the PDO as ReturnedForReconciliation after confirming
     * resource release. The Host must reconcile the returned PDO's state before reuse.
     *
     * @param array<array-key, mixed> $bindings
     * @return int|list<array<array-key, mixed>>
     */
    public function runRegisteredCommand(string $commandId, array $bindings = []): int|array
    {
        return $this->source->executeCheckoutCommand($this, $commandId, $bindings);
    }

    /**
     * Ends a qualified or unqualified active transaction by committing without
     * chaining or releasing the session. Invalidates its grants before the command.
     * After confirming the same idle session, this call returns normally.
     * The checkout becomes Idle if the next-transaction characteristics are
     * resolved, or Quarantined if they remain unconfirmed. Failed confirmation
     * also quarantines the source and throws. release() permits a later
     * checkout only from Idle, after confirming the same idle session and
     * resolved settings.
     * In quarantine the Host can discard the source or call
     * PdoHostSnapshotSource::retireForHost(), which returns the PDO as
     * ReturnedForReconciliation after confirming resource release.
     * The Host must reconcile the returned PDO's state before reuse.
     */
    public function commit(): void
    {
        $this->source->finishCheckout($this, true);
    }

    /**
     * Ends a qualified or unqualified active transaction by rolling back without
     * chaining or releasing the session. Invalidates its grants before the
     * command. After confirming the same idle session, this call returns
     * normally. The checkout becomes Idle if the next-transaction characteristics
     * are resolved, or Quarantined if they remain unconfirmed. Failed
     * confirmation also quarantines the source and throws. release() permits a
     * later checkout only from Idle, after confirming the same idle session
     * and resolved settings.
     * In quarantine the Host can discard the source or call
     * PdoHostSnapshotSource::retireForHost(), which returns the PDO as
     * ReturnedForReconciliation after confirming resource release.
     * The Host must reconcile the returned PDO's state before reuse.
     */
    public function rollBack(): void
    {
        $this->source->finishCheckout($this, false);
    }

    /**
     * Authorizes one admitted read for a catalog query on the qualified
     * generation. The binding map must cover exactly the union of the plan's
     * names. Running a command that invalidates the generation voids the authorization.
     *
     * @param array<array-key, mixed> $bindings
     */
    public function authorizePage(string $queryId, array $bindings, PageRequest $request): HostSnapshotPageGrant
    {
        return $this->source->authorizeCheckoutPage($this, $queryId, $bindings, $request);
    }

    /**
     * Creates a per-invocation result slot for a grant belonging to this checkout.
     * Creating an attempt spends no grant. Admission consumes it when paginating.
     */
    public function newAttempt(HostSnapshotPageGrant $grant): HostSnapshotPageAttempt
    {
        return $this->source->newCheckoutAttempt($this, $grant);
    }

    /**
     * Ends this checkout's authority after confirming the idle session and
     * resolved settings. The source retains the PDO and can issue another checkout.
     */
    public function release(): void
    {
        $this->source->releaseCheckout($this);
    }

    private function __clone() {}

    /**
     * @return array<array-key, mixed>
     *
     * @throws HostSnapshotPaginationException Always. The checkout holds authority issued by its live source.
     */
    public function __serialize(): array
    {
        throw new HostSnapshotPaginationException('A Host snapshot checkout cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws HostSnapshotPaginationException Always. Checkout authority must be issued by the source.
     */
    public function __unserialize(array $data): void
    {
        throw new HostSnapshotPaginationException('A Host snapshot checkout cannot be restored.');
    }
}
