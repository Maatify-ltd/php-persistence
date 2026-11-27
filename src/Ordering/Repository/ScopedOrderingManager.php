<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Repository;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use PDO;
use Throwable;

/**
 * Manages integer ordering positions inside a table.
 *
 * The manager supports:
 * - global ordering: one sequence for the whole table
 * - scoped ordering: one sequence per scope value, e.g. per method_id/product_id
 * - nullable scoped ordering: one sequence for NULL and one sequence per non-null scope value
 *
 * Transaction model:
 * - moveWithinScope() uses the
 *   {@see \Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner}
 *   (DEC-007, DEC-011).
 * - It owns the transaction when called outside an active PDO transaction.
 * - It participates in an active caller-owned PDO transaction without
 *   committing or fully rolling it back. After the scope lock and the
 *   read-only checks, it creates an operation-local savepoint through
 *   {@see \Maatify\PhpPersistence\Transaction\Repository\SavepointScope}
 *   (DEC-011 item 2) before the first mutating statement.
 *   The savepoint allows best-effort rollback of a failed mutation.
 * - Locking and rollback require a transactional storage engine with row locks
 *   and savepoints, such as InnoDB. The host owns the table schema.
 *
 * Ordering model:
 * - getNextPosition() returns max(order) + 1 without requesting a locking read.
 * - getNextPositionForUpdate() returns max(order) + 1 from the latest committed
 *   state using a locking read inside an active caller-owned PDO transaction.
 * - moveWithinScope() locks the whole ordering scope with SELECT ... FOR UPDATE,
 *   then reads the current row order from the database inside the transaction.
 * - The positive newOrder is capped at the locked maximum, with a minimum
 *   cap of 1. The cap reflects the locking read even when the caller's
 *   REPEATABLE READ snapshot is older.
 *
 * The caller controls the PDO error mode. Statement failures reported without
 * a thrown exception raise OrderingExecutionException. Exceptions raised by
 * PDO propagate unchanged.
 *
 * Tests with MariaDB innodb_snapshot_isolation=ON observed driver code 1020
 * when a locking read visited a row changed after an older caller snapshot.
 * The PDOException propagated unchanged and the server rolled back the
 * caller transaction. DEC-009 defines the optional protected checkout used
 * by {@see ProtectedScopedOrderingManager}.
 */
final readonly class ScopedOrderingManager
{
    /**
     * Returns the next available order position within the configured scope.
     *
     * If the table has no rows in the scope, returns 1.
     * Soft-deleted rows are ignored when deletedAtColumn is configured.
     *
     * Note:
     * This method starts no transaction and requests a plain read.
     * Concurrent inserts need caller-side serialization, such as a lock held by
     * the caller's transaction.
     *
     * Snapshot rule: this plain read uses the transaction's consistent-read
     * snapshot. Under REPEATABLE READ that snapshot is created by the
     * transaction's first non-locking read, and locking reads (FOR UPDATE) do
     * not refresh it. Locking the scope or a parent row afterwards therefore
     * does not make this read current, and repeating it (for example to retry
     * after a duplicate-key error) returns the same stale value. The read is
     * current only when no older snapshot exists: outside a transaction, under
     * READ COMMITTED, or when the caller's serialization lock (a lock that every
     * competing writer takes first, for example on a parent row) is taken before
     * the transaction's first non-locking read.
     *
     * Both next-position reads are supported. Use getNextPositionForUpdate() to
     * allocate a position for an insert inside a caller-owned transaction, in
     * particular under REPEATABLE READ; use this plain read for previews and
     * for the cases above where no older snapshot exists.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     * @throws OrderingExecutionException When the read fails without throwing, yields no row, or produces an invalid or unrepresentable position.
     * @throws Throwable When a PDO operation throws.
     */
    public function getNextPosition(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue = null,
    ): int {
        return (new OrderingOperations())->getNextPosition($pdo, $config, $scopeValue);
    }

    /**
     * Returns the next available order position from the latest committed state,
     * using a locking read inside the caller-owned transaction.
     *
     * Requires an active PDO transaction on the given connection and never
     * begins, commits, or rolls back one; the lock is held until the caller ends
     * the transaction, so perform the insert on the same connection and
     * transaction. A locking read (SELECT ... FOR UPDATE ... ORDER BY order DESC,
     * id DESC LIMIT 1) returns the latest committed state instead of the
     * transaction's REPEATABLE READ snapshot. The row it selects and locks is the
     * last active row by the configured order column, with the configured id
     * column as the tie-breaker.
     *
     * A successful allocation requires two consecutive locking reads with the
     * same order value, including for an empty scope. Repeating the read lets
     * a waiter see a higher tail committed while it waited under READ COMMITTED.
     * The budget counts from the first read and allows a match on the last read.
     * If the tail still changes, the call throws after closing the last cursor
     * with reason TAIL_READ_LIMIT_EXCEEDED and maxTailReads in exception metadata.
     * Driver, fetch and cursor errors retain priority. The Host keeps control
     * of the transaction, its locks and any decision to retry.
     *
     * Guarantee: the returned position reflects the latest committed state, and
     * on a non-empty scope concurrent callers that also use locking reads (this
     * method, moveWithinScope()) are serialized on the scope's last active row.
     *
     * Limits:
     * - An empty scope has no row to lock, so concurrent first inserts are not
     *   serialized; the caller must serialize them (e.g. lock a parent row or
     *   rely on a UNIQUE constraint).
     * - Lock footprint depends on the access plan. With an index usable for
     *   (scope, order) the scan stops at the last active row; every soft-deleted
     *   row above it is scanned and locked as well. Without such an index the
     *   scan covers, and locks, every row of the table.
     * - Under REPEATABLE READ the lock also covers the gap above the last
     *   scanned row up to the next index record, which may belong to another
     *   scope. Under READ COMMITTED no gap lock is taken.
     * - Writers that do not use locking reads are not serialized, except by the
     *   REPEATABLE READ gap lock described above.
     * - Locks taken by earlier reads of a repeated call are kept until the
     *   transaction ends, so a call that repeats holds locks on every tail it
     *   read (the earlier tail and the newer one). This slightly widens the
     *   lock footprint and the contention and deadlock surface compared with a
     *   single read.
     * - The call may wait for locks and fail with a lock wait timeout or
     *   deadlock. The failure follows the configured PDO error mode, and
     *   retrying is the caller's responsibility. Under REPEATABLE READ, a call
     *   queued behind a transaction that then inserts above the locked row
     *   typically fails with a deadlock instead of resuming.
     *
     * MariaDB innodb_snapshot_isolation: tests with the setting ON observed
     * PDOException with driver code 1020 after a locking read touched a row
     * changed since an older REPEATABLE READ snapshot. The exception
     * propagated unchanged, the server had rolled back the caller's
     * transaction, and PDO could still report it active. The package does
     * not retry 1020 or change session settings. See DEC-009.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     * @param int $maxTailReads Maximum locking reads per call, from 2 to 512 inclusive. Defaults to 64. Server lock-wait policy applies to each read.
     *
     * @throws InvalidOrderingOperationException When the budget or scope is invalid or no PDO transaction is active.
     * @throws OrderingExecutionException When the budget is exhausted, a statement fails without throwing, or a position exceeds the PHP integer range.
     * @throws Throwable When a PDO/database operation throws.
     */
    public function getNextPositionForUpdate(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue = null,
        int $maxTailReads = 64,
    ): int {
        return (new OrderingOperations())->readStableTail($pdo, $config, $scopeValue, $maxTailReads);
    }

    /**
     * Moves a row inside its ordering scope.
     *
     * This method does NOT trust a caller-provided current order. It locks the
     * whole ordering scope first, then reads the current order directly from
     * the database inside the same transaction.
     *
     * The scope lock serializes concurrent reorder operations within the same
     * scope and prevents overlapping range updates from interleaving on a
     * transactional table that provides row locks and savepoints.
     *
     * The positive $newOrder is capped at the locked maximum, with a minimum
     * cap of 1. This avoids introducing gaps beyond the existing maximum.
     * The range shift runs before the target leaves its original position.
     * A unique key on positions globally or within a scope can reject the
     * intermediate update even when the intended final order is valid.
     *
     * Return values:
     * - true when the move succeeds.
     * - true when the target row is already at the requested/clamped position.
     * - false when the target row does not exist in the given scope.
     * - false when the target update affects zero rows and the move is undone.
     * A false result from an owned move requires rollback acknowledgement and
     * an idle PDO transaction state. A rollback failure propagates.
     *
     * In a caller-owned transaction, a range-shift or target-write failure
     * triggers best-effort cleanup of the operation-local savepoint
     * ({@see \Maatify\PhpPersistence\Transaction\Repository\SavepointScope}).
     * The helper attempts rollback to the savepoint if PDO reports an active
     * transaction, then attempts release only after rollback is acknowledged.
     * The original Throwable propagates unchanged. A zero-row target update
     * requires successful rollback to the savepoint and release before returning
     * false. A failure of either control operation propagates.
     * A no-op move and a missing row issue no savepoint statement. A successful
     * mutation releases its savepoint. While the transaction remains active,
     * it retains the scope lock and the caller keeps earlier work and their
     * own savepoints. The caller still owns commit and rollback. A deadlock (1213),
     * the MariaDB 1020 hazard, or a lock-wait timeout (1205) with
     * innodb_rollback_on_timeout=ON can end the caller's whole transaction on
     * the server. The failure follows the configured PDO error mode. This
     * method does not retry or recover it. A best-effort guard throws
     * TransactionExecutionException before the first mutation when the SAVEPOINT
     * reply shows that the transaction has ended; it is not a proof of
     * transaction identity.
     *
     * The same MariaDB 1020 hazard described for getNextPositionForUpdate()
     * was observed at the scope lock, before this method opens a savepoint.
     * When PDO throws, the original exception propagates unchanged. An
     * operation-local savepoint cannot restore a transaction rolled back by
     * the server.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     * @param string|null $updatedAtValue Valid YYYY-MM-DD HH:MM:SS with an optional fraction of 1–6 digits when configured. The original value is bound.
     *
     * @throws InvalidOrderingOperationException When the row ID, order, scope or timestamp is invalid.
     * @throws OrderingExecutionException When a statement fails without throwing or a stored order cannot be represented as a PHP integer.
     * @throws TransactionExecutionException When transaction control fails without throwing or required settlement cannot be confirmed.
     * @throws Throwable When a PDO/database operation throws.
     */
    public function moveWithinScope(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue = null,
    ): bool {
        return (new OrderingOperations())->moveWithinScope($pdo, $config, $scopeValue, $id, $newOrder, $updatedAtValue);
    }

    /**
     * Checks whether a row exists inside the configured scope.
     *
     * Soft-deleted rows are treated as non-existing when deletedAtColumn is configured.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     * @throws OrderingExecutionException When the read fails without throwing.
     */
    public function rowExistsInScope(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
    ): bool {
        return (new OrderingOperations())->rowExistsInScope($pdo, $config, $scopeValue, $id);
    }
}
