<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

/**
 * Why the protected checkout settled. Read the disposition separately when
 * deciding whether the delegated connection can return to circulation.
 */
enum ProtectedTransactionReasonEnum
{
    case CANCELLED_BEFORE_QUALIFICATION;
    case SERVER_QUALIFICATION_FAILED;
    /**
     * The start command or its active-session confirmation failed.
     */
    case START_UNCONFIRMED;
    /**
     * A recognized engine signal invalidated authority to use the transaction. It does not prove rollback.
     */
    case TRANSACTION_LOST;
    /**
     * The checkout could not establish safe completion of an operation or continuity before ending it.
     */
    case OPERATION_UNCONFIRMED;
    /**
     * Commit was attempted, but its result was not acknowledged.
     */
    case COMMIT_OUTCOME_UNKNOWN;
    /**
     * Commit was acknowledged before the subsequent idle-session check failed.
     */
    case COMMIT_CONFIRMED_CONNECTION_UNSAFE;
    /**
     * The requested rollback or its required idle-session confirmation failed.
     */
    case ROLLBACK_UNCONFIRMED;
    case COMMITTED;
    case ROLLED_BACK;
    case HOST_DISCARDED;
}
