<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\DTO;

use JsonSerializable;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionOutcomeEnum;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionReasonEnum;

/**
 * Immutable local evidence that separates operation effects, whole-transaction
 * effects and PDO disposition. Later checkout actions publish a new snapshot.
 */
final readonly class VerifiedTransactionSettlementDTO implements JsonSerializable
{
    /**
     * @param ?VerifiedTransactionOutcomeEnum $operationOutcome Effect of the operation, or null before an attempt records an effect.
     * @param ?VerifiedTransactionReasonEnum $operationReason Reason recorded for the operation, or null when none was recorded.
     * @param ?VerifiedTransactionOutcomeEnum $transactionOutcome Whole-transaction evidence. Null means no outcome has been recorded,
     *                                                            including while the Host transaction remains open.
     * @param ?VerifiedTransactionReasonEnum $checkoutReason Reason the checkout reached a terminal failure or was retired early,
     *                                                        or null when neither was recorded.
     * @param ?ProtectedTransactionDispositionEnum $disposition Null while held, Unchanged for untouched cancellation,
     *                                                          Reusable after confirmed completion and DiscardRequired to forbid handoff.
     */
    public function __construct(
        public ?VerifiedTransactionOutcomeEnum $operationOutcome,
        public ?VerifiedTransactionReasonEnum $operationReason,
        public ?VerifiedTransactionOutcomeEnum $transactionOutcome,
        public ?VerifiedTransactionReasonEnum $checkoutReason,
        public ?ProtectedTransactionDispositionEnum $disposition,
    ) {}

    /**
     * Projects the stable settlement codes and keeps unrecorded fields null.
     *
     * @return array{operationOutcome: ?string, operationReason: ?string, transactionOutcome: ?string, checkoutReason: ?string, disposition: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'operationOutcome' => $this->operationOutcome?->settlementCode(),
            'operationReason' => $this->operationReason?->settlementCode(),
            'transactionOutcome' => $this->transactionOutcome?->settlementCode(),
            'checkoutReason' => $this->checkoutReason?->settlementCode(),
            'disposition' => $this->disposition?->settlementCode(),
        ];
    }
}
