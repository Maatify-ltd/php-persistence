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
    case NotAccepted;
    case ReturnedToHost;
    case HostActionRequired;

    /** @internal Stable code for settlement JSON. */
    public function settlementCode(): string
    {
        return match ($this) {
            self::NotAccepted => 'NOT_ACCEPTED',
            self::ReturnedToHost => 'RETURNED_TO_HOST',
            self::HostActionRequired => 'HOST_ACTION_REQUIRED',
        };
    }
}
