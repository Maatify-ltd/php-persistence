<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

enum VerifiedTransactionReasonEnum
{
    case QUALIFICATION_FAILED;
    case START_UNCONFIRMED;
    case CONTINUITY_UNCONFIRMED;
    case SAVEPOINT_UNCONFIRMED;
    case CALLBACK_FAILED;
    case COMMAND_FAILED;
    case CURSOR_UNCONFIRMED;
    case ROLLBACK_TO_UNCONFIRMED;
    case RELEASE_UNCONFIRMED;
    case COMMIT_OUTCOME_UNKNOWN;
    case ROLLBACK_UNCONFIRMED;
    case HANDOFF_UNCONFIRMED;
    case TRANSACTION_LOST;
    case HOST_DISCARDED;
}
