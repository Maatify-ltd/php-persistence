<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotBindings;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotAccessModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum as State;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandEffectEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandResultModeEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use PDO;
use PDOStatement;
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
    private State $state = State::IDLE;
    private bool $busy = false;
    private ?string $sessionId = null;
    private ?object $generation = null;
    private bool $nextTransactionUnconfirmed = false;

    private function __construct(
        private HostSnapshotConnection $connection,
        private readonly HostSnapshotCatalog $catalog,
    ) {}

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
            $this->confirmSession(false);
            $this->checkout = null;
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
            $this->generation = new \stdClass();
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
                $result = $this->connection->execute($command->sql, $params, $maxRows);
                $this->confirmSession($preserves ? $active : null);
                if (! $preserves) {
                    $this->state = $this->connection->transactionActive() ? State::TRANSACTION_UNQUALIFIED : State::QUARANTINED;
                }

                return $result;
            } catch (Throwable $error) {
                $this->quarantine();
                throw $error;
            }
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
