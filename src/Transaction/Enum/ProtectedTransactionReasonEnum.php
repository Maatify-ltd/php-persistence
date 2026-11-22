<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

enum ProtectedTransactionReasonEnum
{
    case CANCELLED_BEFORE_QUALIFICATION;
    case SERVER_QUALIFICATION_FAILED;
    case START_UNCONFIRMED;
    case TRANSACTION_LOST;
    case OPERATION_UNCONFIRMED;
    case COMMIT_OUTCOME_UNKNOWN;
    case COMMIT_CONFIRMED_CONNECTION_UNSAFE;
    case ROLLBACK_UNCONFIRMED;
    case COMMITTED;
    case ROLLED_BACK;
    case HOST_DISCARDED;
}
