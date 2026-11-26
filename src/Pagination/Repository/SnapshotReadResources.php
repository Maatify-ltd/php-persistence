<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Exception\SnapshotPaginationException;
use PDOStatement;

/** @internal Tracks the currently owned statement until its cursor is confirmed closed. */
final class SnapshotReadResources
{
    private ?PDOStatement $statement = null;

    private bool $closeAttempted = false;

    /**
     * Registers one statement. An unresolved cursor prevents replacing it with a new one.
     */
    public function track(PDOStatement $statement): void
    {
        if ($this->statement !== null) {
            throw new SnapshotPaginationException('A snapshot read cursor is still outstanding.');
        }

        $this->statement = $statement;
    }

    /**
     * Attempts to close the tracked cursor once. A failed close remains
     * unconfirmed and cannot be retried by a later cleanup step.
     */
    public function release(): void
    {
        if ($this->statement === null) {
            return;
        }

        if ($this->closeAttempted) {
            throw new SnapshotPaginationException('The previous snapshot cursor release was not confirmed.');
        }
        $this->closeAttempted = true;
        if (! $this->statement->closeCursor()) {
            throw new SnapshotPaginationException('Could not release snapshot read resources.');
        }

        $this->statement = null;
        $this->closeAttempted = false;
    }
}
