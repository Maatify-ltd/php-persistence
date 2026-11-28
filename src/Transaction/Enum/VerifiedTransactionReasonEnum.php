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
    case QualificationFailed;

    /** START or the active session check immediately after it could not be confirmed. */
    case StartUnconfirmed;

    /** The checkout could not confirm continued use of the qualified transaction. */
    case ContinuityUnconfirmed;

    /** Creating the Host operation savepoint could not be confirmed. */
    case SavepointUnconfirmed;

    /** The callback threw or returned an invalid decision, and its cleanup was confirmed. */
    case CallbackFailed;

    /** A registered command latched a driver failure. */
    case CommandFailed;

    /** Command cursor release could not be confirmed. */
    case CursorUnconfirmed;

    /** Rollback to the Host operation savepoint could not be confirmed. */
    case RollbackToUnconfirmed;

    /** Savepoint release could not be confirmed. An earlier acknowledged local undo still stands. */
    case ReleaseUnconfirmed;

    /** COMMIT was attempted without a confirmed result. */
    case CommitOutcomeUnknown;

    /** Whole-transaction ROLLBACK was attempted without a confirmed result. */
    case RollbackUnconfirmed;

    /** The end was acknowledged, but the idle-session check for handoff failed. */
    case HandoffUnconfirmed;

    /** The engine signalled a recognized transaction-loss condition. */
    case TransactionLost;

    /** The Host retired a checkout before it reached a terminal disposition. */
    case HostDiscarded;

    /** @internal Stable code for settlement JSON. */
    public function settlementCode(): string
    {
        return match ($this) {
            self::QualificationFailed => 'QUALIFICATION_FAILED',
            self::StartUnconfirmed => 'START_UNCONFIRMED',
            self::ContinuityUnconfirmed => 'CONTINUITY_UNCONFIRMED',
            self::SavepointUnconfirmed => 'SAVEPOINT_UNCONFIRMED',
            self::CallbackFailed => 'CALLBACK_FAILED',
            self::CommandFailed => 'COMMAND_FAILED',
            self::CursorUnconfirmed => 'CURSOR_UNCONFIRMED',
            self::RollbackToUnconfirmed => 'ROLLBACK_TO_UNCONFIRMED',
            self::ReleaseUnconfirmed => 'RELEASE_UNCONFIRMED',
            self::CommitOutcomeUnknown => 'COMMIT_OUTCOME_UNKNOWN',
            self::RollbackUnconfirmed => 'ROLLBACK_UNCONFIRMED',
            self::HandoffUnconfirmed => 'HANDOFF_UNCONFIRMED',
            self::TransactionLost => 'TRANSACTION_LOST',
            self::HostDiscarded => 'HOST_DISCARDED',
        };
    }
}
