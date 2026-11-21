<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Repository;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionStatement;
use Maatify\PhpPersistence\Transaction\Repository\SavepointScope;
use PDO;
use PDOStatement;
use Throwable;

/** @internal Shared Ordering SQL and validation for raw and exclusively delegated connections. */
final readonly class OrderingOperations
{
    public function getNextPosition(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue = null,
    ): int {
        $this->assertScopeUsage($config, $scopeValue);

        [$whereSql, $params] = $this->buildScopeWhere($config, $scopeValue);

        $stmt = $this->prepareStatement(
            $pdo,
            __FUNCTION__,
            'SELECT COALESCE(MAX(' . $config->quotedOrderColumn() . '), 0)
               FROM ' . $config->quotedTable()
            . $whereSql,
        );

        $this->executeStatement($stmt, $params, __FUNCTION__);

        $value = $this->fetchIntegerOrEof($stmt, __FUNCTION__);
        $this->closeCursor($stmt, __FUNCTION__);

        if ($value === false) {
            throw new OrderingExecutionException('The next-position aggregate did not return a row.');
        }

        return $this->nextPosition($value);
    }

    public function readStableTail(
        PDO|ProtectedTransactionConnection $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        ?int $maxTailReads,
    ): int {
        if ($maxTailReads !== null && ($maxTailReads < 2 || $maxTailReads > 512)) {
            throw new InvalidOrderingOperationException('The ordering tail-read limit must be between 2 and 512.');
        }
        $operation = 'getNextPositionForUpdate';
        $this->assertScopeUsage($config, $scopeValue);

        if (!$pdo->inTransaction()) {
            throw new InvalidOrderingOperationException(
                'A locking next-position read requires an active PDO transaction.',
            );
        }

        [$whereSql, $params] = $this->buildScopeWhere($config, $scopeValue);

        // ORDER BY ... DESC LIMIT 1 lets InnoDB stop at the last active row when
        // an (scope, order) index exists. MAX(...) FOR UPDATE would lock every
        // row of the scanned scope range instead.
        $stmt = $this->prepareStatement(
            $pdo,
            $operation,
            'SELECT ' . $config->quotedOrderColumn() . '
               FROM ' . $config->quotedTable()
            . $whereSql
            . ' ORDER BY ' . $config->quotedOrderColumn() . ' DESC, ' . $config->quotedIdColumn() . ' DESC
              LIMIT 1
              FOR UPDATE',
        );

        // A read that waited for the lock on the previous tail can resume after
        // another transaction committed a higher tail (observable under READ
        // COMMITTED). Repeat the locking read until it returns the same order
        // value twice, so the position is derived from a tail this transaction
        // holds.
        $previousTail = null;

        $remaining = $maxTailReads;
        while ($remaining === null || $remaining > 0) {
            if ($remaining !== null) {
                --$remaining;
            }
            $this->executeStatement($stmt, $params, $operation);

            $value = $this->fetchIntegerOrEof($stmt, $operation);
            $this->closeCursor($stmt, $operation);

            $tail = $value === false ? 0 : $value;

            if ($tail === $previousTail) {
                return $this->nextPosition($tail);
            }

            $previousTail = $tail;
        }

        throw new OrderingExecutionException(
            'Unable to confirm a stable ordering tail within the read budget.',
            meta: ['reason' => 'TAIL_READ_LIMIT_EXCEEDED', 'maxTailReads' => $maxTailReads],
        );
    }

    public function moveWithinScope(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue = null,
    ): bool {
        $this->validateMove($config, $scopeValue, $id, $newOrder, $updatedAtValue);
        $rollbackResult = new class extends \RuntimeException {};
        $participating = $pdo->inTransaction();

        try {
            return (new PdoTransactionRunner($pdo))->run(function () use (
                $pdo,
                $config,
                $scopeValue,
                $id,
                $newOrder,
                $updatedAtValue,
                $participating,
                $rollbackResult,
            ): bool {
                if (!$this->move($pdo, $config, $scopeValue, $id, $newOrder, $updatedAtValue, $participating)) {
                    throw $rollbackResult;
                }

                return true;
            });
        } catch (Throwable $failure) {
            if ($failure === $rollbackResult) {
                return false;
            }

            throw $failure;
        }
    }

    public function moveProtected(
        ProtectedTransactionConnection $connection,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue,
    ): bool {
        $this->validateMove($config, $scopeValue, $id, $newOrder, $updatedAtValue);

        return $this->move($connection, $config, $scopeValue, $id, $newOrder, $updatedAtValue, true);
    }

    private function validateMove(
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue,
    ): void {
        $this->assertScopeUsage($config, $scopeValue);
        $this->assertUpdatedAtUsage($config, $updatedAtValue);

        if ($id <= 0) {
            throw new InvalidOrderingOperationException('Invalid row id.');
        }
        if ($newOrder <= 0) {
            throw new InvalidOrderingOperationException('New ordering position must be a positive integer.');
        }
    }

    private function move(
        PDO|ProtectedTransactionConnection $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue,
        bool $participating,
    ): bool {
        $maxOrder = $this->lockScopeForUpdate($pdo, $config, $scopeValue);
        $currentOrder = $this->getCurrentOrderForUpdate($pdo, $config, $scopeValue, $id);
        if ($currentOrder === null) {
            return false;
        }

        $newOrder = min($newOrder, max(1, $maxOrder));
        if ($currentOrder === $newOrder) {
            return true;
        }

        // Protect only this mutation window in a caller-owned transaction.
        $savepoint = $participating ? SavepointScope::begin($pdo) : null;
        try {
            if ($newOrder < $currentOrder) {
                $this->shiftOrdersUp($pdo, $config, $scopeValue, $newOrder, $currentOrder);
            } else {
                $this->shiftOrdersDown($pdo, $config, $scopeValue, $currentOrder, $newOrder);
            }
            $updated = $this->updateTargetOrder($pdo, $config, $scopeValue, $id, $newOrder, $updatedAtValue);
        } catch (Throwable $failure) {
            $savepoint?->cleanupAfterFailure();
            throw $failure;
        }

        if (!$updated) {
            $savepoint?->undoOrFail();

            return false;
        }

        $savepoint?->releaseOrUndo();

        return true;
    }

    public function rowExistsInScope(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
    ): bool {
        $this->assertScopeUsage($config, $scopeValue);

        if ($id <= 0) {
            return false;
        }

        $where = [];
        $params = ['id' => $id];

        $where[] = $config->quotedIdColumn() . ' = :id';
        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $stmt = $this->prepareStatement(
            $pdo,
            __FUNCTION__,
            'SELECT 1
               FROM ' . $config->quotedTable() . '
              WHERE ' . implode(' AND ', $where) . '
              LIMIT 1',
        );

        $this->executeStatement($stmt, $params, __FUNCTION__);

        $value = $this->fetchIntegerOrEof($stmt, __FUNCTION__);
        $this->closeCursor($stmt, __FUNCTION__);

        return (bool) $value;
    }

    /**
     * Locks all active rows in the configured ordering scope.
     *
     * This serializes concurrent reorder operations within the same scope and
     * prevents overlapping range updates from interleaving.
     *
     * For global ordering, this locks all active rows in the table.
     * For scoped ordering, this locks only rows matching the scope value.
     *
     * Soft-deleted rows are ignored when deletedAtColumn is configured.
     *
     * Returns the highest order position among the locked rows, or 0 for an
     * empty scope. The maximum is taken from this locking read, which returns the
     * latest committed state, and not from a separate non-locking read: under
     * REPEATABLE READ a plain read in a caller-owned transaction uses an older
     * snapshot that locking reads do not refresh, which would make the movement
     * clamp stale. Rows arrive in ascending order, so the last row read holds the
     * maximum; the cursor is read row by row and closed without building an array.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    private function lockScopeForUpdate(
        PDO|ProtectedTransactionConnection $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
    ): int {
        $where = [];
        $params = [];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->prepareStatement(
            $pdo,
            __FUNCTION__,
            'SELECT ' . $config->quotedIdColumn() . ', ' . $config->quotedOrderColumn() . '
               FROM ' . $config->quotedTable()
            . $whereSql
            . ' ORDER BY ' . $config->quotedOrderColumn() . ' ASC, ' . $config->quotedIdColumn() . ' ASC
              FOR UPDATE',
        );

        $this->executeStatement($stmt, $params, __FUNCTION__);

        $maxOrder = 0;

        // Column 1 is the order column; false marks the end of the result set.
        while (($order = $this->fetchIntegerOrEof($stmt, __FUNCTION__, 1)) !== false) {
            $maxOrder = $order;
        }

        $this->closeCursor($stmt, __FUNCTION__);

        return $maxOrder;
    }

    /**
     * Reads and locks the target row order.
     *
     * The ordering scope is already locked by lockScopeForUpdate(). This extra
     * FOR UPDATE keeps the target-row read explicit and safe if this method is
     * changed or reused later.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    private function getCurrentOrderForUpdate(
        PDO|ProtectedTransactionConnection $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
    ): ?int {
        $where = [];
        $params = ['id' => $id];

        $where[] = $config->quotedIdColumn() . ' = :id';
        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $stmt = $this->prepareStatement(
            $pdo,
            __FUNCTION__,
            'SELECT ' . $config->quotedOrderColumn() . '
               FROM ' . $config->quotedTable() . '
              WHERE ' . implode(' AND ', $where) . '
              LIMIT 1
              FOR UPDATE',
        );

        $this->executeStatement($stmt, $params, __FUNCTION__);

        $value = $this->fetchIntegerOrEof($stmt, __FUNCTION__);
        $this->closeCursor($stmt, __FUNCTION__);

        return $value === false ? null : $value;
    }

    /**
     * Shifts rows up when moving the target row to a smaller order value.
     *
     * Example:
     * current = 5, new = 2
     * rows 2..4 become 3..5.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    private function shiftOrdersUp(
        PDO|ProtectedTransactionConnection $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $newOrder,
        int $currentOrder,
    ): void {
        $where = [];
        $params = [
            'new_order_where' => $newOrder,
            'current_order' => $currentOrder,
        ];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $where[] = $config->quotedOrderColumn() . ' >= :new_order_where';
        $where[] = $config->quotedOrderColumn() . ' < :current_order';

        $stmt = $this->prepareStatement(
            $pdo,
            __FUNCTION__,
            'UPDATE ' . $config->quotedTable() . '
                SET ' . $config->quotedOrderColumn() . ' = ' . $config->quotedOrderColumn() . ' + 1
              WHERE ' . implode(' AND ', $where),
        );

        $this->executeStatement($stmt, $params, __FUNCTION__);
    }

    /**
     * Shifts rows down when moving the target row to a larger order value.
     *
     * Example:
     * current = 2, new = 5
     * rows 3..5 become 2..4.
     *
     * @param int|string|null $scopeValue Required when config has a scope column; null for global ordering.
     */
    private function shiftOrdersDown(
        PDO|ProtectedTransactionConnection $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $newOrder,
        int $currentOrder,
    ): void {
        $where = [];
        $params = [
            'new_order_where' => $newOrder,
            'current_order' => $currentOrder,
        ];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $where[] = $config->quotedOrderColumn() . ' <= :new_order_where';
        $where[] = $config->quotedOrderColumn() . ' > :current_order';

        $stmt = $this->prepareStatement(
            $pdo,
            __FUNCTION__,
            'UPDATE ' . $config->quotedTable() . '
                SET ' . $config->quotedOrderColumn() . ' = ' . $config->quotedOrderColumn() . ' - 1
              WHERE ' . implode(' AND ', $where),
        );

        $this->executeStatement($stmt, $params, __FUNCTION__);
    }

    /**
     * Updates the target row to its final order value.
     *
     * @param int|string|null $scopeValue Required when config has a scope column; null for global ordering.
     */
    private function updateTargetOrder(
        PDO|ProtectedTransactionConnection $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue,
    ): bool {
        $where = [];
        $params = [
            'id'             => $id,
            'new_order_case' => $newOrder,
        ];

        $where[] = $config->quotedIdColumn() . ' = :id';
        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $set = $config->quotedOrderColumn() . ' = :new_order_case';
        $updatedAtColumn = $config->quotedUpdatedAtColumn();
        if ($updatedAtColumn !== null) {
            $set .= ', ' . $updatedAtColumn . ' = :updated_at_value';
            $params['updated_at_value'] = $updatedAtValue;
        }

        $stmt = $this->prepareStatement(
            $pdo,
            __FUNCTION__,
            'UPDATE ' . $config->quotedTable() . '
                SET ' . $set . '
              WHERE ' . implode(' AND ', $where),
        );

        $this->executeStatement($stmt, $params, __FUNCTION__);

        return $stmt->rowCount() > 0;
    }

    private function prepareStatement(PDO|ProtectedTransactionConnection $pdo, string $operation, string $sql): PDOStatement|ProtectedTransactionStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new OrderingExecutionException(sprintf('Failed to prepare the ordering statement for %s.', $operation));
        }

        return $statement;
    }

    /** @param array<string, int|string|null> $params */
    private function executeStatement(PDOStatement|ProtectedTransactionStatement $statement, array $params, string $operation): void
    {
        if (!$statement->execute($params)) {
            throw new OrderingExecutionException(sprintf('Failed to execute the ordering statement for %s.', $operation));
        }
    }

    /**
     * All selected columns are numeric. In these queries false denotes EOF
     * only when the driver reports a successful statement. Read SQLSTATE
     * before closing the cursor, which can replace it.
     */
    private function fetchIntegerOrEof(PDOStatement|ProtectedTransactionStatement $statement, string $operation, int $column = 0): int|false
    {
        try {
            $value = $statement->fetchColumn($column);
            if ($value === false) {
                if ($statement->errorCode() !== '00000') {
                    throw new OrderingExecutionException(sprintf('Failed to fetch the ordering result for %s.', $operation));
                }

                return false;
            }

            return $this->parseStoredInteger($value);
        } catch (Throwable $failure) {
            // Release an open result before the transaction owner attempts rollback.
            try {
                $statement->closeCursor();
            } catch (Throwable) {
                // Cursor cleanup must not replace the primary read failure.
            }

            throw $failure;
        }
    }

    /**
     * PDO may return integer columns as decimal strings, including unsigned
     * numbers greater than PHP_INT_MAX. Check the magnitude before casting
     * so distinct positions cannot collapse into the same PHP integer.
     */
    private function parseStoredInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/\A([+-]?)([0-9]+)\z/', $value, $parts) !== 1) {
            throw new OrderingExecutionException('The ordering result must be an integer.');
        }

        $digits = ltrim($parts[2], '0');
        if ($digits === '') {
            return 0;
        }

        $negative = $parts[1] === '-';
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new OrderingExecutionException('The ordering result is outside the PHP integer range.');
        }

        return (int) (($negative ? '-' : '') . $digits);
    }

    private function nextPosition(int $maximum): int
    {
        if ($maximum === PHP_INT_MAX) {
            throw new OrderingExecutionException('The next ordering position is outside the PHP integer range.');
        }

        return $maximum + 1;
    }

    private function closeCursor(PDOStatement|ProtectedTransactionStatement $statement, string $operation): void
    {
        if (!$statement->closeCursor()) {
            throw new OrderingExecutionException(sprintf('Failed to close the ordering result for %s.', $operation));
        }
    }

    /**
     * Builds the scope and soft-delete WHERE clause shared by position reads.
     *
     * @param int|string|null $scopeValue Scope value for scoped ordering.
     *
     * @return array{0: string, 1: array<string, int|string|null>} WHERE SQL (empty or with leading space) and bound parameters.
     */
    private function buildScopeWhere(ScopedOrderingConfig $config, int|string|null $scopeValue): array
    {
        $where = [];
        $params = [];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        return [$where !== [] ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * Appends the configured scope condition to a WHERE clause.
     *
     * If config has no scope column, this method intentionally does nothing.
     *
     * @param int|string|null                $scopeValue Scope value for scoped ordering; NULL is supported when nullableScope is enabled.
     * @param list<string>                   $where      WHERE clause fragments.
     * @param array<string, int|string|null> $params     Bound parameter map.
     */
    private function appendScopeCondition(
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        array &$where,
        array &$params,
    ): void {
        $scopeColumn = $config->quotedScopeColumn();

        if ($scopeColumn === null) {
            return;
        }

        if ($scopeValue === null && !$config->nullableScope) {
            throw new InvalidOrderingOperationException('Scope column requires a scope value.');
        }

        if ($scopeValue === null) {
            $where[] = $scopeColumn . ' IS NULL';

            return;
        }

        $where[] = $scopeColumn . ' = :scope_value';
        $params['scope_value'] = $scopeValue;
    }

    /**
     * Appends a soft-delete condition when configured.
     *
     * If deletedAtColumn is null, soft-delete filtering is disabled.
     *
     * @param list<string> $where WHERE clause fragments.
     */
    private function appendSoftDeleteCondition(
        ScopedOrderingConfig $config,
        array &$where,
    ): void {
        $deletedAtColumn = $config->quotedDeletedAtColumn();

        if ($deletedAtColumn === null) {
            return;
        }

        $where[] = $deletedAtColumn . ' IS NULL';
    }

    /**
     * Ensures that scope configuration and scope value are used consistently.
     *
     * Valid:
     * - scopeColumn = null, scopeValue = null
     * - scopeColumn = "method_id", scopeValue = 10
     * - scopeColumn = "parent_id", nullableScope = true, scopeValue = null
     *
     * Invalid:
     * - scopeColumn = null, scopeValue = 10
     * - scopeColumn = "method_id", nullableScope = false, scopeValue = null
     *
     * @param int|string|null $scopeValue Scope value for scoped ordering.
     */
    private function assertScopeUsage(
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
    ): void {
        if ($config->scopeColumn === null && $scopeValue !== null) {
            throw new InvalidOrderingOperationException('Scope value was provided without a scope column.');
        }

        if ($config->scopeColumn !== null && $scopeValue === null && !$config->nullableScope) {
            throw new InvalidOrderingOperationException('Scope column requires a scope value.');
        }
    }

    /**
     * Ensures that timestamp configuration and the operation value are used
     * consistently.
     *
     * @param string|null $updatedAtValue Timestamp value for the target mutation.
     */
    private function assertUpdatedAtUsage(
        ScopedOrderingConfig $config,
        ?string $updatedAtValue,
    ): void {
        if ($config->updatedAtColumn === null && $updatedAtValue !== null) {
            throw new InvalidOrderingOperationException(
                'An updated-at value was provided without an updated-at column.',
            );
        }

        if ($config->updatedAtColumn !== null && $updatedAtValue === null) {
            throw new InvalidOrderingOperationException(
                'An updated-at column requires an updated-at value.',
            );
        }
    }
}
