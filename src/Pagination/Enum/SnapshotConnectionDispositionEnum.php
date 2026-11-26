<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Enum;

/** The operation's disposition for the Host's single checkout. */
enum SnapshotConnectionDispositionEnum
{
    /**
     * A known pre-start refusal. Return control to the Host without granting fresh checkout clearance.
     */
    case UNCHANGED;
    /**
     * The same session was confirmed idle after the acknowledged end and cursor release.
     */
    case REUSABLE;
    /**
     * Session, resources or completion could not be confirmed. The Host must retire the checkout.
     */
    case DISCARD;
}
