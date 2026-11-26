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
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use PDO;
use RuntimeException;
use Throwable;

enum Intent
{
    case APPLY;
    case DISCARD;
}

/**
 * The proof callback's explicit Apply or Discard intent and independent
 * result payload. The selected intent, rather than payload truthiness,
 * controls how the fixture resolves the operation.
 */
final readonly class Decision
{
    public function __construct(public Intent $intent, public mixed $value) {}
}

/**
 * Experimental operation window. C1 uses this with the existing Host checkout.
 * C2 uses the same mechanism under a separate caller-facing fixture below.
 * The private seam exercises the real claim, busy check, driver latch and session
 * check. It is deliberately unavailable to package consumers.
 */
final class Window
{
    public const SAVEPOINT = 'maatify_wub22_proof';

    private bool $attempted = false;
    private string $effect = 'NOT_STARTED';
    private ?string $unconfirmed = null;
    private bool $workEntered = false;

    public function __construct(
        private readonly PdoProtectedTransactionCheckout $checkout,
        private readonly ProtectedTransactionCommandCatalog $catalog,
    ) {}

    /** @return array{effect: string, unconfirmed: ?string} Caller knowledge only. */
    public function evidence(): array
    {
        return ['effect' => $this->effect, 'unconfirmed' => $this->unconfirmed];
    }

    public function entered(): bool
    {
        return $this->workEntered;
    }

    /**
     * Runs the proof window once. In Host mode it resolves the local savepoint
     * and returns the callback payload. In Owned mode it returns the decision
     * to the fixture so the fixture can resolve the enclosing transaction.
     *
     * @param Closure(Closure(string, array<string, int>): (int|array)): Decision $work
     * @param ?Closure(string): bool $beforeControl Fixture only: false/throw before dispatch.
     */
    public function run(Closure $work, bool $host = true, ?Closure $beforeControl = null): mixed
    {
        if ($this->attempted) {
            throw new LogicException('The experiment window can be attempted once.');
        }
        $this->attempted = true;
        // The actual Protected checkout owns the reservation even in C2.
        $lease = Closure::bind(
            static function (PdoProtectedTransactionCheckout $checkout, Closure $operation): mixed {
                return $checkout->run(static function (ProtectedTransactionConnection $receiver) use ($checkout, $operation): Decision {
                    try {
                        $checkout->inspect(true, $checkout->activeSession());
                    } catch (Throwable $failure) {
                        $receiver->fail($failure);
                    }

                    return $operation($receiver);
                });
            },
            null,
            PdoProtectedTransactionCheckout::class,
        );
        if ($lease === null) {
            throw new LogicException('Experiment could not reach the existing Protected reservation.');
        }

        $decision = $lease($this->checkout, function (ProtectedTransactionConnection $receiver) use ($work, $host, $beforeControl): Decision {
            if ($host) {
                $this->control($receiver, 'SAVEPOINT', $beforeControl);
            }

            $scopeOpen = true;
            $command = $this->commandScope($receiver, $scopeOpen);
            $this->workEntered = true;
            $this->effect = 'UNKNOWN';
            try {
                try {
                    $result = $work($command);
                } finally {
                    // The application grant closes before any SQL for settlement.
                    $scopeOpen = false;
                }
            } catch (Throwable $primary) {
                if ($host && $receiver->failure() === null) {
                    try {
                        $this->undo($receiver, $beforeControl);
                    } catch (Throwable) {
                        // Preserve the primary object. The receiver owns the control failure latch.
                    }
                }

                throw $primary;
            }

            // A callback can catch a driver exception. It cannot clear the receiver's latch.
            $receiver->ensureAvailable();
            if ($host) {
                if ($result->intent === Intent::DISCARD) {
                    $this->undo($receiver, $beforeControl);
                } else {
                    $this->control($receiver, 'RELEASE SAVEPOINT', $beforeControl);
                }
            }

            return $result;
        });

        if (!$decision instanceof Decision) {
            throw new LogicException('Unexpected decision type in proof.');
        }
        if (!$host) {
            return $decision;
        }
        if ($decision->intent === Intent::APPLY) {
            $this->effect = 'PENDING_HOST';
        }

        return $decision->value;
    }

    /**
     * @return Closure(string, array<string, int>=): (int|array)
     */
    private function commandScope(ProtectedTransactionConnection $receiver, bool &$scopeOpen): Closure
    {
        $catalog = $this->catalog;

        return static function (string $id, array $bindings = []) use ($catalog, $receiver, &$scopeOpen): int|array {
            if (!$scopeOpen) {
                throw new LogicException('The experiment command scope has expired.');
            }
            $receiver->ensureAvailable();
            $plan = $catalog->command($id);
            $statement = $receiver->prepare($plan->sql);
            foreach ($plan->bind($bindings) as $name => $value) {
                $statement->bind($name, $value);
            }
            $statement->execute();
            try {
                $result = $plan->maxRows === null
                    ? $statement->rowCount()
                    : self::boundedRows($statement->row(...), $plan->maxRows);
            } catch (Throwable $primary) {
                try {
                    $statement->closeCursor();
                } catch (Throwable) {
                    // Cleanup remains latched on the receiver.
                }

                throw $primary;
            }
            $statement->closeCursor();

            return $result;
        };
    }

    /**
     * @param Closure(): (array<array-key, mixed>|false) $read
     * @return list<array<array-key, mixed>>
     */
    private static function boundedRows(Closure $read, int $limit): array
    {
        $rows = [];
        while (($row = $read()) !== false) {
            if (count($rows) === $limit) {
                throw new RuntimeException('The proof row bound was exceeded.');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param ?Closure(string): bool $beforeControl */
    private function undo(ProtectedTransactionConnection $receiver, ?Closure $beforeControl): void
    {
        $this->control($receiver, 'ROLLBACK TO SAVEPOINT', $beforeControl);
        $this->effect = 'LOCAL_UNDONE';
        $this->control($receiver, 'RELEASE SAVEPOINT', $beforeControl);
    }

    /** @param ?Closure(string): bool $beforeControl */
    private function control(ProtectedTransactionConnection $receiver, string $command, ?Closure $beforeControl): void
    {
        $this->unconfirmed = $command;
        try {
            if ($beforeControl !== null && !$beforeControl($command)) {
                throw new RuntimeException('Fixture control returned false before dispatch: ' . $command);
            }
        } catch (Throwable $failure) {
            $receiver->fail($failure);
        }
        $receiver->control($command . ' ' . self::SAVEPOINT);
        $this->unconfirmed = null;
    }
}

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
            $this->finish($decision->intent === Intent::APPLY);
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
                $reason = Reason::OPERATION_UNCONFIRMED;
                try {
                    $effect('UNKNOWN');
                    $checkout->inspect(true, $session);
                    $reason = $commit ? Reason::COMMIT_OUTCOME_UNKNOWN : Reason::ROLLBACK_UNCONFIRMED;
                    $checkout->control($commit ? 'COMMIT AND NO CHAIN NO RELEASE' : 'ROLLBACK AND NO CHAIN NO RELEASE');
                    $effect($commit ? 'COMMITTED' : 'ROLLED_BACK');
                    if ($commit) {
                        $reason = Reason::COMMIT_CONFIRMED_CONNECTION_UNSAFE;
                    }
                    $checkout->inspect(false, $session);
                    $checkout->settle(Disposition::REUSABLE, $commit ? Reason::COMMITTED : Reason::ROLLED_BACK);
                } catch (Throwable $failure) {
                    $checkout->settle(Disposition::DISCARD_REQUIRED, $reason);

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
