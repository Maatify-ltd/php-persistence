<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotRetirementDispositionEnum;
use PDO;

/**
 * Returns the same delegated PDO after confirmed resource release. Its
 * disposition tells the Host whether it was known idle or needs reconciliation
 * before reuse. The PDO is a live PHP handoff with no JSON contract.
 */
final readonly class HostSnapshotRetirement
{
    /** @internal Created once the source has accepted the Host's request to retire. */
    public function __construct(
        public HostSnapshotRetirementDispositionEnum $disposition,
        public PDO $pdo,
    ) {}
}
