<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Proof;

use Closure;
use LogicException;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\DTO\ProtectedTransactionSettlementDTO;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use PDO;
use Throwable;

/**
 * Historical proof fixture for verified Apply and Discard. Both paths use
 * the Protected checkout claim and private reservation seam to test actual
 * exclusivity. The production Verified checkout is implemented separately.
 */
final class ParallelCheckout
{
    private readonly PdoProtectedTransactionCheckout $checkout;
    public readonly Window $window;
    public string $ownedEffect = 'NOT_STARTED';
    private ?string $endEffect = null;

    public function __construct(PDO $pdo, ProtectedTransactionCommandCatalog $catalog)
    {
        $this->checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog);
        $this->window = new Window($this->checkout, $catalog);
    }

    public function beginHost(): void
    {
        $this->checkout->begin();
    }

    /** @param array<string, int> $bindings */
    public function hostCommand(string $id, array $bindings = []): int|array
    {
        return $this->checkout->runRegisteredCommand($id, $bindings);
    }

    /**
     * Uses the local savepoint and returns the payload after applying or undoing
     * the window. The Host still controls the outer transaction.
     *
     * @param Closure(Closure(string, array<string, int>): (int|array)): Decision $work
     */
    public function runInHost(Closure $work, ?Closure $fault = null): mixed
    {
        return $this->window->run($work, beforeControl: $fault);
    }

    /**
     * Starts and ends the entire fixture transaction according to the explicit
     * decision before returning its payload. An escaping callback error remains
     * primary if cleanup also fails.
     *
     * @param Closure(Closure(string, array<string, int>): (int|array)): Decision $work
     */
    public function runOwned(Closure $work): mixed
    {
        $this->checkout->begin();
        try {
            $decision = $this->window->run($work, host: false);
            if (!$decision instanceof Decision) {
                throw new LogicException('Unexpected owned decision type in proof.');
            }
        } catch (Throwable $primary) {
            $this->ownedEffect = $this->window->entered() ? 'UNKNOWN' : 'NOT_STARTED';
            if ($this->checkout->settlement() === null) {
                try {
                    $this->rollBack();
                } catch (Throwable) {
                    // Keep the callback as the primary failure.
                } finally {
                    $this->ownedEffect = $this->endEffect ?? $this->ownedEffect;
                }
            }

            throw $primary;
        }

        try {
            $this->finish($decision->intent === Intent::Apply);
        } finally {
            $this->ownedEffect = $this->endEffect ?? 'UNKNOWN';
        }

        return $decision->value;
    }

    public function commit(): void
    {
        $this->finish(true);
    }

    public function rollBack(): void
    {
        $this->finish(false);
    }

    public function endEffect(): ?string
    {
        return $this->endEffect;
    }

    /**
     * Compare C2's end-effect projection under the real lease. Capture the ACK
     * between the end SQL and idle probe. The existing settlement is retained
     * as the source of the connection disposition, with its original enum.
     */
    private function finish(bool $commit): void
    {
        $finish = Closure::bind(
            static function (PdoProtectedTransactionCheckout $checkout, bool $commit, Closure $effect): void {
                $session = $checkout->activeSession();
                $checkout->reserve();
                $reason = Reason::OperationUnconfirmed;
                try {
                    $effect('UNKNOWN');
                    $checkout->inspect(true, $session);
                    $reason = $commit ? Reason::CommitOutcomeUnknown : Reason::RollbackUnconfirmed;
                    $checkout->control($commit ? 'COMMIT AND NO CHAIN NO RELEASE' : 'ROLLBACK AND NO CHAIN NO RELEASE');
                    $effect($commit ? 'COMMITTED' : 'ROLLED_BACK');
                    if ($commit) {
                        $reason = Reason::CommitConfirmedConnectionUnsafe;
                    }
                    $checkout->inspect(false, $session);
                    $checkout->settle(Disposition::Reusable, $commit ? Reason::Committed : Reason::RolledBack);
                } catch (Throwable $failure) {
                    $checkout->settle(Disposition::DiscardRequired, $reason);

                    throw $failure;
                } finally {
                    $checkout->busy = false;
                }
            },
            null,
            PdoProtectedTransactionCheckout::class,
        );
        if ($finish === null) {
            throw new LogicException('Experiment could not reach the existing end reservation.');
        }

        $finish($this->checkout, $commit, function (string $effect): void {
            $this->endEffect = $effect;
        });
    }

    public function settlement(): ?ProtectedTransactionSettlementDTO
    {
        return $this->checkout->settlement();
    }

    public function returnForHost(): PDO
    {
        return $this->checkout->returnForHost();
    }

    public function discardForHost(): void
    {
        $this->checkout->discardForHost();
    }
}
