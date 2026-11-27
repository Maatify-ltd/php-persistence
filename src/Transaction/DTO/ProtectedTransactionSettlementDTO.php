<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\DTO;

use JsonSerializable;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum;

/**
 * Immutable terminal evidence from the protected checkout. The disposition
 * governs handoff, while the reason records what the checkout observed. An
 * acknowledged database effect can coexist with a required discard.
 */
final readonly class ProtectedTransactionSettlementDTO implements JsonSerializable
{
    /** @internal Issued by the checkout when its outcome is terminal. */
    public function __construct(
        public ProtectedTransactionDispositionEnum $disposition,
        public ProtectedTransactionReasonEnum $reason,
    ) {}

    /**
     * Projects the recorded enum case names as strings.
     *
     * @return array{disposition: string, reason: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'disposition' => $this->disposition->name,
            'reason' => $this->reason->name,
        ];
    }
}
