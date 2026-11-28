<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

/**
 * Why the protected checkout settled. Read the disposition separately when
 * deciding whether the delegated connection can return to circulation.
 */
enum ProtectedTransactionReasonEnum
{
    case CancelledBeforeQualification;
    case ServerQualificationFailed;
    /**
     * The start command or its active-session confirmation failed.
     */
    case StartUnconfirmed;
    /**
     * A recognized engine signal invalidated authority to use the transaction. It does not prove rollback.
     */
    case TransactionLost;
    /**
     * The checkout could not establish safe completion of an operation or continuity before ending it.
     */
    case OperationUnconfirmed;
    /**
     * Commit was attempted, but its result was not acknowledged.
     */
    case CommitOutcomeUnknown;
    /**
     * Commit was acknowledged before the subsequent idle-session check failed.
     */
    case CommitConfirmedConnectionUnsafe;
    /**
     * The requested rollback or its required idle-session confirmation failed.
     */
    case RollbackUnconfirmed;
    case Committed;
    case RolledBack;
    case HostDiscarded;

    /** @internal Stable code for settlement JSON. */
    public function settlementCode(): string
    {
        return match ($this) {
            self::CancelledBeforeQualification => 'CANCELLED_BEFORE_QUALIFICATION',
            self::ServerQualificationFailed => 'SERVER_QUALIFICATION_FAILED',
            self::StartUnconfirmed => 'START_UNCONFIRMED',
            self::TransactionLost => 'TRANSACTION_LOST',
            self::OperationUnconfirmed => 'OPERATION_UNCONFIRMED',
            self::CommitOutcomeUnknown => 'COMMIT_OUTCOME_UNKNOWN',
            self::CommitConfirmedConnectionUnsafe => 'COMMIT_CONFIRMED_CONNECTION_UNSAFE',
            self::RollbackUnconfirmed => 'ROLLBACK_UNCONFIRMED',
            self::Committed => 'COMMITTED',
            self::RolledBack => 'ROLLED_BACK',
            self::HostDiscarded => 'HOST_DISCARDED',
        };
    }
}
