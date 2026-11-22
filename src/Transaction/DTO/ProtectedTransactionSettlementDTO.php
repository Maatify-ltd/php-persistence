<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\DTO;

use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum;

final readonly class ProtectedTransactionSettlementDTO
{
    /** @internal Issued by the checkout when its outcome is terminal. */
    public function __construct(
        public ProtectedTransactionDispositionEnum $disposition,
        public ProtectedTransactionReasonEnum $reason,
    ) {}
}
