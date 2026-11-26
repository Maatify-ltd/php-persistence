<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotRetirementDispositionEnum;
use PDO;

/**
 * Returns the same delegated PDO after confirmed resource release. Its
 * disposition tells the Host whether it was known idle or needs reconciliation
 * before reuse.
 */
final readonly class HostSnapshotRetirementDTO
{
    /** @internal Created once the source has accepted the Host's request to retire. */
    public function __construct(
        public HostSnapshotRetirementDispositionEnum $disposition,
        public PDO $pdo,
    ) {}
}
