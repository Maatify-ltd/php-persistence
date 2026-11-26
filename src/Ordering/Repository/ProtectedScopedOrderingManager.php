<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Repository;

use Closure;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;

/**
 * Allocates and moves positions under the reservation of an active protected
 * checkout. The Host controls transaction completion and its locks.
 */
final readonly class ProtectedScopedOrderingManager
{
    /**
     * Uses a checkout with a confirmed active transaction and returns the next
     * position after two consecutive matching locking reads of the scope tail.
     * Returns 1 for a stable empty tail. A null scope means global Ordering or
     * the configured nullable scope.
     *
     * The budget is 2 to 512 reads and defaults to 64. A match on the last
     * allowed read counts. Each read remains subject to server lock-wait policy.
     * Exhaustion raises OrderingExecutionException with reason
     * TAIL_READ_LIMIT_EXCEEDED and the budget as maxTailReads. The Host keeps
     * its locks and can decide the outcome while the checkout remains active.
     * An unconfirmed result cleanup or transaction continuity requires discard.
     *
     * @throws \Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException For an invalid scope or read budget.
     * @throws \Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException If the read budget is exhausted or a position cannot be represented as a PHP integer.
     * @throws \Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException If the checkout is unavailable or the session cannot be confirmed.
     * @throws \PDOException The original driver failure, when the driver throws.
     */
    public function getNextPositionForUpdate(
        PdoProtectedTransactionCheckout $checkout,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue = null,
        int $maxTailReads = 64,
    ): int {
        return $this->inCheckout(
            $checkout,
            static fn(ProtectedTransactionConnection $connection): int => (new OrderingOperations())->readStableTail(
                $connection,
                $config,
                $scopeValue,
                $maxTailReads,
            ),
        );
    }

    /**
     * Moves a positive row ID within the scope in a confirmed active checkout.
     * The positive target position is capped at the locked scope maximum,
     * with a minimum cap of 1. A null scope means global Ordering or the
     * configured nullable scope. Supply a timestamp exactly when an updated-at
     * column is configured.
     *
     * A successful move or no-op returns true. A missing row or an undone
     * zero-row update returns false. A mutating move uses a local savepoint.
     * Savepoint or driver failure fences the checkout and preserves the first
     * error. The Host owns completion and can inspect
     * {@see PdoProtectedTransactionCheckout::settlement()} after an exception.
     *
     * @throws \Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException For invalid movement inputs.
     * @throws \Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException If a stored position cannot be represented as a PHP integer.
     * @throws \Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException If the checkout is unavailable or the session cannot be confirmed.
     * @throws \PDOException The original driver failure, when the driver throws.
     */
    public function moveWithinScope(
        PdoProtectedTransactionCheckout $checkout,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue = null,
    ): bool {
        return $this->inCheckout(
            $checkout,
            static fn(ProtectedTransactionConnection $connection): bool => (new OrderingOperations())->moveProtected(
                $connection,
                $config,
                $scopeValue,
                $id,
                $newOrder,
                $updatedAtValue,
            ),
        );
    }

    /**
     * Calculates the next dense position under the anchor lock, with room for
     * one staging position after append. The Host performs the insert on the
     * same checkout and decides when to end the transaction. Pass null for a
     * global list or the positive anchor ID for a scoped list.
     *
     * An eligibility rejection can leave the checkout active if resource
     * cleanup and transaction continuity are confirmed. After an exception,
     * read the checkout's settlement(). DISCARD_REQUIRED fences further SQL
     * and requires the Host to call discardForHost().
     *
     * @throws InvalidOrderingOperationException For a scope ID outside the profile.
     * @throws OrderingExecutionException If eligibility or append capacity cannot be confirmed.
     * @throws ProtectedTransactionException If the checkout is unavailable or fenced, or a protected statement or the active session cannot be confirmed.
     * @throws \PDOException The original driver failure, when the driver throws.
     */
    public function getNextUniquePositionForAppend(
        PdoProtectedTransactionCheckout $checkout,
        UniqueOrderingConfig $config,
        ?int $scopeId,
    ): int {
        return $this->inUniqueCheckout(
            $checkout,
            $config,
            $scopeId,
            static fn(UniqueOrderingOperations $operations): int => $operations->next(),
        );
    }

    /**
     * Moves a row within dense positions 1..N under the active checkout.
     * The caller supplies a positive ID and position. A mutating move uses a
     * local savepoint and needs a free staging position. False means the
     * target is absent from an eligible scope. True confirms local completion
     * or a no-op pending the Host transaction outcome.
     *
     * After a mutation fails, the checkout can remain active only if local
     * undo, resource cleanup and continuity are confirmed. After an exception,
     * read the checkout's settlement(). DISCARD_REQUIRED fences further SQL
     * and requires the Host to call discardForHost().
     *
     * @throws InvalidOrderingOperationException For an invalid identifier or position.
     * @throws OrderingExecutionException If eligibility, the move or its result cannot be confirmed.
     * @throws ProtectedTransactionException If the checkout is unavailable or fenced, or a protected statement or the active session cannot be confirmed.
     * @throws \PDOException The original driver failure, when the driver throws.
     */
    public function moveUniqueWithinScope(
        PdoProtectedTransactionCheckout $checkout,
        UniqueOrderingConfig $config,
        ?int $scopeId,
        int $id,
        int $newOrder,
    ): bool {
        return $this->inUniqueCheckout(
            $checkout,
            $config,
            $scopeId,
            static fn(UniqueOrderingOperations $operations): bool => $operations->move($id, $newOrder),
        );
    }

    /**
     * Runs a restricted registered DELETE and compacts the scope in one local
     * savepoint. The Host authorizes deletion and decides commit or rollback.
     * The command ID identifies the exact affected-row DELETE registered in the
     * active checkout. False means an eligible scope lacks the target. True
     * confirms local completion pending Host commit.
     *
     * After a mutation fails, the checkout can remain active only if local
     * undo, resource cleanup and continuity are confirmed. After an exception,
     * read the checkout's settlement(). DISCARD_REQUIRED fences further SQL
     * and requires the Host to call discardForHost().
     *
     * @throws InvalidOrderingOperationException For an invalid identifier or ineligible DELETE plan.
     * @throws ProtectedTransactionException For an unregistered command, invalid binding declaration, unavailable or fenced checkout, or unconfirmed protected statement or active session.
     * @throws OrderingExecutionException If eligibility, the delete or compaction cannot be confirmed.
     * @throws \PDOException The original driver failure, when the driver throws.
     */
    public function deleteUniqueWithinScope(
        PdoProtectedTransactionCheckout $checkout,
        UniqueOrderingConfig $config,
        ?int $scopeId,
        int $id,
        string $deleteCommandId,
    ): bool {
        return $this->inUniqueCheckout(
            $checkout,
            $config,
            $scopeId,
            static fn(UniqueOrderingOperations $operations, ProtectedTransactionCommandCatalog $catalog): bool
                => $operations->delete($id, $catalog->command($deleteCommandId)),
        );
    }

    /**
     * Resolves the selected plan and session inside the existing private reservation.
     *
     * @template T of int|bool
     * @param Closure(UniqueOrderingOperations, ProtectedTransactionCommandCatalog): T $operation
     * @return T
     */
    private function inUniqueCheckout(
        PdoProtectedTransactionCheckout $checkout,
        UniqueOrderingConfig $config,
        ?int $scopeId,
        Closure $operation,
    ): int|bool {
        $reserve = Closure::bind(
            static fn(): int|bool => $checkout->run(
                static fn(ProtectedTransactionConnection $receiver): int|bool
                    => $operation(new UniqueOrderingOperations($receiver, $checkout->activeSession(), $config, $scopeId), $checkout->catalog),
            ),
            null,
            PdoProtectedTransactionCheckout::class,
        );

        return $reserve();
    }

    /**
     * The Checkout has no public callback entry point. Only the Ordering
     * repository binds this narrowly scoped access to its private reservation.
     *
     * @template T of int|bool
     * @param Closure(ProtectedTransactionConnection): T $operation
     * @return T
     */
    private function inCheckout(PdoProtectedTransactionCheckout $checkout, Closure $operation): int|bool
    {
        $reserve = Closure::bind(
            static fn(): int|bool => $checkout->run($operation),
            null,
            PdoProtectedTransactionCheckout::class,
        );
        return $reserve();
    }
}
