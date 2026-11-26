<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/**
 * What the Host can do after one attempt. NOT_ACCEPTED means no page SQL ran.
 * RETURNED_TO_HOST restores checkout control after scope confirmation, with the
 * PDO still held by the source. HOST_ACTION_REQUIRED denotes quarantine.
 */
enum HostSnapshotPageDispositionEnum
{
    case NOT_ACCEPTED;
    case RETURNED_TO_HOST;
    case HOST_ACTION_REQUIRED;
}
