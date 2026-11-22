<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

enum VerifiedTransactionOutcomeEnum
{
    case NOT_STARTED;
    case PENDING_HOST;
    case LOCAL_UNDONE;
    case COMMITTED;
    case ROLLED_BACK;
    case UNKNOWN;
}
