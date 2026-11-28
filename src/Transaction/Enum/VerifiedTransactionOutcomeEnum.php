<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

/**
 * Evidence about the operation or whole transaction, as identified by the
 * settlement field. Read the disposition separately to decide PDO handoff.
 */
enum VerifiedTransactionOutcomeEnum
{
    /** The recorded operation or transaction did not start. */
    case NotStarted;

    /** The operation was applied inside the still-open Host transaction. */
    case PendingHost;

    /** Rollback to the operation savepoint was acknowledged, even if release later failed. */
    case LocalUndone;

    /** The commit of the whole transaction was acknowledged. */
    case Committed;

    /** The rollback of the whole transaction was acknowledged. */
    case RolledBack;

    /** The effect could not be confirmed. Reconcile before deciding whether to repeat work. */
    case Unknown;

    /** @internal Stable code for settlement JSON. */
    public function settlementCode(): string
    {
        return match ($this) {
            self::NotStarted => 'NOT_STARTED',
            self::PendingHost => 'PENDING_HOST',
            self::LocalUndone => 'LOCAL_UNDONE',
            self::Committed => 'COMMITTED',
            self::RolledBack => 'ROLLED_BACK',
            self::Unknown => 'UNKNOWN',
        };
    }
}
