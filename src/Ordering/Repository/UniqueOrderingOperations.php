<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Repository;

use Closure;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionCommandExecutor;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use Maatify\PhpPersistence\Transaction\Repository\SavepointScope;
use Throwable;

/** @internal Runs B1 operations solely inside the Ordering manager's reserved checkout. */
final readonly class UniqueOrderingOperations
{
    private UniqueOrderingSql $sql;
    private string $table;
    private string $idColumn;
    private string $position;
    private string $scopePredicate;
    /** @var array<string, int> */
    private array $scopeBindings;

    /**
     * Binds the reserved connection to the expected session and selected scope.
     * Global lists take null, and scoped lists take the positive anchor ID.
     */
    public function __construct(
        private ProtectedTransactionConnection $connection,
        private string $session,
        private UniqueOrderingConfig $config,
        private ?int $scopeId,
    ) {
        $scope = $config->ordering->quotedScopeColumn();
        if ($scope === null ? $scopeId !== null : ($scopeId ?? 0) < 1) {
            throw new InvalidOrderingOperationException('B1 requires a positive scoped ID or a null global scope.');
        }
        $this->sql = new UniqueOrderingSql($connection);
        $this->table = $config->ordering->quotedTable();
        $this->idColumn = $config->ordering->quotedIdColumn();
        $this->position = $config->ordering->quotedOrderColumn();
        $this->scopePredicate = $scope === null ? '' : ' AND ' . $scope . ' = :scope_id';
        $this->scopeBindings = $scopeId === null ? [] : ['scope_id' => $scopeId];
    }

    /**
     * Calculates the append position after locking and validating the dense
     * list. Leaving a staging slot after append is part of the capacity check.
     * The Host inserts while that transaction retains the anchor lock.
     */
    public function next(): int
    {
        $size = count($this->lockScope(null));
        if ($size >= $this->config->maxScopeRows) {
            UniqueOrderingSql::reject('SCOPE_ROW_LIMIT', 'B1 append exceeds the scope row limit.');
        }
        if ($size >= $this->config->maxPosition - 1) {
            UniqueOrderingSql::reject('NO_STAGING_POSITION', 'B1 append must leave one free staging position.');
        }

        return $size + 1;
    }

    /**
     * Moves a present row to a position in 1..N. A mutating move needs a free
     * staging slot and confirms the resulting dense order under a savepoint.
     * An eligible missing row returns false, and a no-op or local success returns true.
     */
    public function move(int $id, int $newOrder): bool
    {
        if ($newOrder < 1) {
            throw new InvalidOrderingOperationException('B1 move requires a positive position.');
        }
        $ids = $this->lockScope($id);
        $index = array_search($id, $ids, true);
        if ($index === false) {
            return false;
        }
        $old = $index + 1;
        $size = count($ids);
        if ($newOrder > $size) {
            throw new InvalidOrderingOperationException('B1 target position must be inside the dense scope.');
        }
        if ($old === $newOrder) {
            return true;
        }
        if ($size >= $this->config->maxPosition) {
            UniqueOrderingSql::reject('NO_STAGING_POSITION', 'B1 move requires a free staging position.');
        }

        $stage = $size + 1;
        array_splice($ids, $index, 1);
        array_splice($ids, $newOrder - 1, 0, [$id]);
        $this->mutate(function () use ($id, $old, $newOrder, $stage, $ids): void {
            $up = $newOrder < $old;
            $matched = $this->changePosition($id, $old, $stage);
            self::requireCount($matched, 1, 'MOVE_INVARIANT_FAILED');
            $matched = $this->shift($up, $up ? $newOrder : $old + 1, $up ? $old - 1 : $newOrder);
            self::requireCount($matched, abs($newOrder - $old), 'MOVE_INVARIANT_FAILED');
            $matched = $this->changePosition($id, $stage, $newOrder);
            self::requireCount($matched, 1, 'MOVE_INVARIANT_FAILED');
            $this->verifyResult($ids, 'MOVE_INVARIANT_FAILED');
        });

        return true;
    }

    /**
     * Checks the exact affected-row DELETE declaration even for an absent row.
     * Returns false if an eligible scope lacks the row, otherwise confirms
     * deletion and compaction within the same savepoint before returning true.
     */
    public function delete(int $id, ProtectedTransactionCommandPlan $command): bool
    {
        $expected = 'DELETE FROM ' . $this->table . ' WHERE ' . $this->idColumn . ' = :id'
            . $this->scopePredicate . ' AND ' . $this->position . ' = :position';
        if ($command->sql !== $expected || $command->maxRows !== null) {
            throw new InvalidOrderingOperationException('B1 delete requires the exact registered affected-row DELETE plan.');
        }
        $bindings = ['id' => $id, 'position' => 1] + $this->scopeBindings;
        $command->bind($bindings); // Check the parameter declaration even if the target is missing.

        $ids = $this->lockScope($id);
        $index = array_search($id, $ids, true);
        if ($index === false) {
            return false;
        }
        $position = $index + 1;
        $size = count($ids);
        $bindings['position'] = $position;
        $bindings = $command->bind($bindings);
        array_splice($ids, $index, 1);
        $this->mutate(function () use ($command, $bindings, $position, $size, $ids): void {
            $matched = (new ProtectedTransactionCommandExecutor($this->connection))->run($command, $bindings, releaseBeforeReturn: true);
            self::requireCount($matched, 1, 'DELETE_INVARIANT_FAILED');
            if ($position < $size) {
                self::requireCount($this->shift(false, $position + 1, $size), $size - $position, 'DELETE_INVARIANT_FAILED');
            }
            $this->verifyResult($ids, 'DELETE_INVARIANT_FAILED');
        });

        return true;
    }

    /**
     * Confirms the session and deployment, then locks the existing anchor
     * before reading the ordered IDs. The anchor coordinates all writers,
     * including on an empty list, under the Host deployment protocol.
     *
     * @return list<int>
     */
    private function lockScope(?int $id): array
    {
        if ($id !== null && $id < 1) {
            throw new InvalidOrderingOperationException('B1 requires a positive row ID.');
        }

        $this->connection->verifyActive($this->session);
        $bounds = (new UniqueOrderingSchema($this->sql, $this->config))->verify();
        $anchor = $this->config->anchor;
        $anchorId = $this->scopeId ?? $this->config->globalAnchorId;
        if ($anchorId === null || $anchorId > $bounds['anchor'] || ($id ?? 0) > $bounds['id']) {
            throw new InvalidOrderingOperationException('B1 identifiers exceed their qualified column bounds.');
        }
        $rows = $this->sql->rows(
            'SELECT ' . $anchor->quotedIdColumn() . ' FROM ' . $anchor->quotedTable()
                . ' WHERE ' . $anchor->quotedIdColumn() . ' = :anchor_id FOR UPDATE',
            ['anchor_id' => $anchorId],
        );
        if (count($rows) !== 1) {
            UniqueOrderingSql::reject('ANCHOR_MISSING', 'B1 requires the pre-existing scope anchor.');
        }

        return $this->readScope($bounds['id']);
    }

    /**
     * Locks and reads a bounded scope in position order. Validates positive
     * row IDs and contiguous positions from 1 before returning.
     *
     * @return list<int>
     */
    private function readScope(int $idLimit): array
    {
        $rows = $this->sql->rows(
            'SELECT ' . $this->idColumn . ' AS row_id, ' . $this->position . ' AS row_position'
                . ' FROM ' . $this->table . ' WHERE 1 = 1' . $this->scopePredicate
                . ' ORDER BY ' . $this->position . ' ASC LIMIT ' . ($this->config->maxScopeRows + 1) . ' FOR UPDATE',
            $this->scopeBindings,
        );
        if (count($rows) > $this->config->maxScopeRows) {
            UniqueOrderingSql::reject('SCOPE_ROW_LIMIT', 'B1 scope exceeds its row limit.');
        }

        $ids = [];
        foreach ($rows as $index => $row) {
            $id = $row['row_id'] ?? null;
            $position = $row['row_position'] ?? null;
            if (!is_int($id) && (!is_string($id) || preg_match('/\A[1-9][0-9]{0,9}\z/', $id) !== 1)) {
                UniqueOrderingSql::reject('INELIGIBLE_SCHEMA', 'B1 requires positive integer row IDs.');
            }
            if ((int) $id < 1 || (int) $id > $idLimit || (string) (int) $id !== (string) $id) {
                UniqueOrderingSql::reject('INELIGIBLE_SCHEMA', 'B1 row ID exceeds the qualified integer range.');
            }
            $expected = $index + 1;
            if (($position !== $expected && $position !== (string) $expected) || $expected > $this->config->maxPosition) {
                UniqueOrderingSql::reject('SCOPE_NOT_DENSE', 'B1 positions must be dense and within the qualified position bound.');
            }
            $ids[] = (int) $id;
        }

        return $ids;
    }

    private function changePosition(int $id, int $old, int $new): int
    {
        return $this->sql->update(
            'UPDATE ' . $this->table . ' SET ' . $this->position . ' = :new_position'
                . ' WHERE ' . $this->idColumn . ' = :id' . $this->scopePredicate . ' AND ' . $this->position . ' = :old_position',
            ['new_position' => $new, 'id' => $id, 'old_position' => $old] + $this->scopeBindings,
        );
    }

    /**
     * Shifts toward the free slot in an order that preserves the UNIQUE key.
     */
    private function shift(bool $increase, int $first, int $last): int
    {
        return $this->sql->update(
            'UPDATE ' . $this->table . ' SET ' . $this->position . ' = ' . $this->position . ($increase ? ' + 1' : ' - 1')
                . ' WHERE ' . $this->position . ' BETWEEN :first_position AND :last_position' . $this->scopePredicate
                . ' ORDER BY ' . $this->position . ($increase ? ' DESC' : ' ASC'),
            ['first_position' => $first, 'last_position' => $last] + $this->scopeBindings,
        );
    }

    /** @param list<int> $expected */
    private function verifyResult(array $expected, string $reason): void
    {
        try {
            $actual = $this->readScope(PHP_INT_MAX);
        } catch (OrderingExecutionException) {
            UniqueOrderingSql::reject($reason, 'B1 could not confirm the resulting dense order.');
        }
        if ($actual !== $expected) {
            UniqueOrderingSql::reject($reason, 'B1 could not confirm the expected row order.');
        }
    }

    private static function requireCount(mixed $actual, int $expected, string $reason): void
    {
        if ($actual !== $expected) {
            UniqueOrderingSql::reject($reason, 'B1 affected-row count did not match the locked scope.');
        }
    }

    /**
     * Confirms the active session and savepoint release before returning.
     * On failure, local undo is attempted only while the receiver is usable.
     * Unconfirmed cleanup is left for settlement and the primary is rethrown.
     *
     * @param Closure(): void $operation
     */
    private function mutate(Closure $operation): void
    {
        $savepoint = SavepointScope::open($this->connection);
        try {
            $this->connection->verifyActive($this->session);
            $operation();
            $this->connection->verifyActive($this->session);
            $savepoint->releaseStrict();
        } catch (Throwable $primary) {
            if ($this->connection->failure() === null) {
                try {
                    $this->connection->verifyActive($this->session);
                    $savepoint->rollbackStrict();
                    $savepoint->releaseStrict();
                } catch (Throwable) {
                    // The protected receiver latches the unconfirmed cleanup for settlement.
                }
            }

            throw $primary;
        }
    }
}
