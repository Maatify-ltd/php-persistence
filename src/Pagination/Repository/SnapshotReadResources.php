<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Exception\SnapshotPaginationException;
use PDOStatement;

/** @internal Tracks the currently owned statement until its cursor is confirmed closed. */
final class SnapshotReadResources
{
    private ?PDOStatement $statement = null;

    public function track(PDOStatement $statement): void
    {
        $this->statement = $statement;
    }

    public function release(): void
    {
        if ($this->statement === null) {
            return;
        }

        if (! $this->statement->closeCursor()) {
            throw new SnapshotPaginationException('Could not release snapshot read resources.');
        }

        $this->statement = null;
    }
}
