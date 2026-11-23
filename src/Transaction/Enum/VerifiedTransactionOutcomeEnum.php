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
    case NOT_STARTED;

    /** The operation was applied inside the still-open Host transaction. */
    case PENDING_HOST;

    /** Rollback to the operation savepoint was acknowledged, even if release later failed. */
    case LOCAL_UNDONE;

    /** The commit of the whole transaction was acknowledged. */
    case COMMITTED;

    /** The rollback of the whole transaction was acknowledged. */
    case ROLLED_BACK;

    /** The effect could not be confirmed. Reconcile before deciding whether to repeat work. */
    case UNKNOWN;
}
