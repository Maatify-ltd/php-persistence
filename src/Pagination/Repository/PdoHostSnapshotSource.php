<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotAttemptRecord;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotBindings;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotGrantRegistration;
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
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotRowLimitExceeded;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use PDO;
use PDOException;
use PDOStatement;
use stdClass;
use Pdo\Mysql;
use Throwable;
use WeakMap;
use WeakReference;

final class PdoHostSnapshotSource
{
    /** @var WeakMap<PDO, true>|null */
    private static ?WeakMap $attached = null;

    /** @var WeakReference<PdoHostSnapshotCheckout>|null */
    private ?WeakReference $checkout = null;
    private ?object $checkoutIdentity = null;
    /** @var WeakMap<HostSnapshotPageGrant, HostSnapshotGrantRegistration> */
    private WeakMap $grants;
    /** @var WeakMap<HostSnapshotPageAttempt, HostSnapshotAttemptRecord> */
    private WeakMap $attempts;
    private State $state = State::IDLE;
    private bool $busy = false;
    private ?string $sessionId = null;
    private ?object $generation = null;
    // Session variables do not reveal a pending one-transaction override.
    private bool $nextTransactionUnconfirmed = true;

    private function __construct(
        private HostSnapshotConnection $connection,
        private readonly HostSnapshotCatalog $catalog,
    ) {
        $this->grants = new WeakMap();
        $this->attempts = new WeakMap();
    }

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

    /** @internal */
    public function checkoutState(PdoHostSnapshotCheckout $checkout): State
    {
        if ($this->checkout?->get() !== $checkout) {
            return State::RELEASED;
        }

        return $this->busy ? State::BUSY : $this->state;
    }

    /** @internal */
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

    /** @internal */
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
     * @internal
     * @param array<array-key, mixed> $bindings
     * @return int|list<array<string, mixed>>
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
                } catch (HostSnapshotRowLimitExceeded) {
                    $result = null;
                }
                $this->confirmSession($preserves ? $active : null);
                if (! $preserves) {
                    $this->state = $this->connection->transactionActive() ? State::TRANSACTION_UNQUALIFIED : State::QUARANTINED;
                }

            } catch (Throwable $error) {
                $this->quarantine();
                throw $error;
            }
            if ($result === null) {
                throw new HostSnapshotPaginationException('The Host snapshot result exceeds its row limit.');
            }

            return $result;
        } finally {
            $this->busy = false;
        }
    }

    /** @internal */
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
        $plan = $this->catalog->page($queryId);
        $grant = HostSnapshotPageGrant::issue();
        $this->grants[$grant] = new HostSnapshotGrantRegistration(
            $this->checkoutIdentity,
            $this->generation,
            $plan,
            $plan->descriptor($bindings),
            $request,
        );

        return $grant;
    }

    /** @internal */
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
                // These validation failures and name-resolution errors cannot
                // finish a Host transaction in an eligible registered read.
                $knownReadError = $error instanceof PaginationExecutionException
                    || ($error instanceof PDOException && in_array(
                        [$error->errorInfo[0] ?? null, $error->errorInfo[1] ?? null],
                        [['42S22', 1054], ['42S02', 1146]],
                        true,
                    ));
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

    private function quarantine(): void
    {
        $this->generation = null;
        $this->state = State::QUARANTINED;
    }

    private function reserve(PdoHostSnapshotCheckout $checkout, State ...$allowed): void
    {
        if ($this->busy || $this->checkout?->get() !== $checkout || ! in_array($this->state, $allowed, true)) {
            throw new HostSnapshotPaginationException('The Host snapshot checkout is unavailable.');
        }
        $this->busy = true;
    }

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

    /** @return array<array-key, mixed> */
    public function __serialize(): array
    {
        throw new HostSnapshotPaginationException('A Host snapshot source cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new HostSnapshotPaginationException('A Host snapshot source cannot be restored.');
    }
}
