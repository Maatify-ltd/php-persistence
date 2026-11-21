<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotRetirementDispositionEnum;
use PDO;

final readonly class HostSnapshotRetirementDTO
{
    /** @internal Created once the source has accepted the Host's request to retire. */
    public function __construct(
        public HostSnapshotRetirementDispositionEnum $disposition,
        public PDO $pdo,
    ) {}
}
