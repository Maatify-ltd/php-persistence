<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

/**
 * Why an operation or checkout could not complete normally, or why the Host
 * retired it. The reason can describe a different failure from the throwable
 * that escapes. Read the corresponding outcome and disposition independently.
 */
enum VerifiedTransactionReasonEnum
{
    /** The initial idle-session qualification failed before START was attempted. */
    case QUALIFICATION_FAILED;

    /** START or the active session check immediately after it could not be confirmed. */
    case START_UNCONFIRMED;

    /** The checkout could not confirm continued use of the qualified transaction. */
    case CONTINUITY_UNCONFIRMED;

    /** Creating the Host operation savepoint could not be confirmed. */
    case SAVEPOINT_UNCONFIRMED;

    /** The callback threw or returned an invalid decision, and its cleanup was confirmed. */
    case CALLBACK_FAILED;

    /** A registered command latched a driver failure. */
    case COMMAND_FAILED;

    /** Command cursor release could not be confirmed. */
    case CURSOR_UNCONFIRMED;

    /** Rollback to the Host operation savepoint could not be confirmed. */
    case ROLLBACK_TO_UNCONFIRMED;

    /** Savepoint release could not be confirmed. An earlier acknowledged local undo still stands. */
    case RELEASE_UNCONFIRMED;

    /** COMMIT was attempted without a confirmed result. */
    case COMMIT_OUTCOME_UNKNOWN;

    /** Whole-transaction ROLLBACK was attempted without a confirmed result. */
    case ROLLBACK_UNCONFIRMED;

    /** The end was acknowledged, but the idle-session check for handoff failed. */
    case HANDOFF_UNCONFIRMED;

    /** The engine signalled a recognized transaction-loss condition. */
    case TRANSACTION_LOST;

    /** The Host retired a checkout before it reached a terminal disposition. */
    case HOST_DISCARDED;
}
