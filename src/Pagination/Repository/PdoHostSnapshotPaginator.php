<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPageAttempt;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;

final readonly class PdoHostSnapshotPaginator
{
    /** @return PageResultDTO<array<array-key, mixed>|object> */
    public function paginate(HostSnapshotPageAttempt $attempt): PageResultDTO
    {
        return $attempt->read();
    }
}
