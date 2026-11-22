<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\DTO;

use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionOutcomeEnum;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionReasonEnum;

/** Immutable local evidence, without a driver handle or authority to use one. */
final readonly class VerifiedTransactionSettlementDTO
{
    public function __construct(
        public ?VerifiedTransactionOutcomeEnum $operationOutcome,
        public ?VerifiedTransactionReasonEnum $operationReason,
        public ?VerifiedTransactionOutcomeEnum $transactionOutcome,
        public ?VerifiedTransactionReasonEnum $checkoutReason,
        public ?ProtectedTransactionDispositionEnum $disposition,
    ) {}
}
