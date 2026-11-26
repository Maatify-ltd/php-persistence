<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/**
 * The Host's declaration of a registered command's effect on page grants.
 * Invalidating clears the generation before execution. Preserving is an
 * attestation that the command cannot change the session or current transaction.
 */
enum HostSnapshotCommandEffectEnum
{
    case INVALIDATE_GENERATION;
    case PRESERVES_TRANSACTION;
}
