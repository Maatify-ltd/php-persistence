<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

enum ProtectedTransactionDispositionEnum
{
    case UNCHANGED;
    case REUSABLE;
    case DISCARD_REQUIRED;
}
