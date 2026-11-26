<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPageAttempt;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;

/**
 * Reads a registered page within an already qualified Host-owned transaction.
 * The Host controls completion through its checkout and retains the attempt to
 * inspect its settlement after a result or exception.
 */
final readonly class PdoHostSnapshotPaginator
{
    /**
     * Invokes an attempt once, participating in its existing transaction without
     * owning transaction completion. A registered attempt settles in its own
     * record. An original driver or mapper throwable keeps its identity.
     *
     * @return PageResultDTO<array<array-key, mixed>|object>
     */
    public function paginate(HostSnapshotPageAttempt $attempt): PageResultDTO
    {
        return $attempt->read();
    }
}
