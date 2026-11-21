<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Repository;

use Closure;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;

/** Ordering within the Host's exclusively delegated write transaction. */
final readonly class ProtectedScopedOrderingManager
{
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
