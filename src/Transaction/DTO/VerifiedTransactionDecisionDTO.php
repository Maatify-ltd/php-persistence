<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\DTO;

/**
 * An explicit transaction decision with an independent application payload.
 * Payloads, including false, null and objects, pass through unchanged when
 * the checkout confirms the requested effect.
 */
final readonly class VerifiedTransactionDecisionDTO
{
    private function __construct(public bool $isApply, public mixed $payload) {}

    /** Selects commit in Owned mode or keeps the operation changes pending in Host mode. */
    public static function apply(mixed $payload = null): self
    {
        return new self(true, $payload);
    }

    /** Selects whole-transaction rollback in Owned mode or savepoint undo in Host mode. */
    public static function discard(mixed $payload = null): self
    {
        return new self(false, $payload);
    }
}
