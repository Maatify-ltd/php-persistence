<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Examples;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotAccessModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageDispositionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotPaginator;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use Throwable;

/**
 * Reads one page in a Host-directed, read-only transaction.
 *
 * Load this example after the Host's production Composer autoloader. The Host
 * supplies an idle source with an exclusively delegated, non-persistent
 * pdo_mysql connection and a trusted, non-locking InnoDB page plan. The source
 * has no other checkout or user during this call. The Host keeps the original
 * PDO out of circulation while it is delegated to the source. The example
 * discards the source on failure or unconfirmed reuse after commit. The Host
 * drops any retained PDO reference after discard.
 *
 * @param string $queryId Identifier of the registered page plan.
 * @param array<string, string|int|bool|null> $bindings Exact named bindings for the plan.
 * @return PageResultDTO<array<array-key, mixed>|object>
 * @throws Throwable The original page failure or the failure to start, authorize, or settle the checkout.
 */
function readHostSnapshotPage(
    PdoHostSnapshotSource $source,
    string $queryId,
    array $bindings,
    PageRequest $request,
): PageResultDTO {
    try {
        $checkout = $source->checkout();
        $checkout->beginRepeatableRead(HostSnapshotAccessModeEnum::ReadOnly);
        $grant = $checkout->authorizePage($queryId, $bindings, $request);
        $attempt = $checkout->newAttempt($grant);

        try {
            $page = (new PdoHostSnapshotPaginator())->paginate($attempt);
        } catch (Throwable $readFailure) {
            // Roll back only while the settlement confirms control of the transaction.
            if ($attempt->settlement()?->disposition === HostSnapshotPageDispositionEnum::ReturnedToHost) {
                try {
                    $checkout->rollBack();
                } catch (Throwable) {
                    // Discard below and preserve the original read failure.
                }
            }

            throw $readFailure;
        }

        // The successful page returns control to the Host. The Host ends the transaction.
        $checkout->commit();
        if ($checkout->state() === HostSnapshotCheckoutStateEnum::Idle) {
            $checkout->release();
        } else {
            // A completed transaction alone does not clear the connection for reuse.
            $source->discardForHost();
        }

        return $page;
    } catch (Throwable $failure) {
        // The Host keeps the connection out of circulation, even if discard fails.
        try {
            $source->discardForHost();
        } catch (Throwable) {
            // Preserve the failure that put the connection out of circulation.
        }

        throw $failure;
    }
}
