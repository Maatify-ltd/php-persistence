<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/** The operation's disposition for the Host's single checkout. */
enum SnapshotConnectionDispositionEnum
{
    /**
     * A known pre-start refusal. Return control to the Host without granting fresh checkout clearance.
     */
    case Unchanged;
    /**
     * The same session was confirmed idle after the acknowledged end and cursor release.
     */
    case Reusable;
    /**
     * Session, resources or completion could not be confirmed. The Host must retire the checkout.
     */
    case Discard;

    /** @internal Stable code for settlement JSON. */
    public function settlementCode(): string
    {
        return match ($this) {
            self::Unchanged => 'UNCHANGED',
            self::Reusable => 'REUSABLE',
            self::Discard => 'DISCARD',
        };
    }
}
