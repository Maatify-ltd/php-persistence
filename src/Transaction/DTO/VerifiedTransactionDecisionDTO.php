<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\DTO;

/** An explicit transaction decision and an independent application payload. */
final readonly class VerifiedTransactionDecisionDTO
{
    private function __construct(public bool $isApply, public mixed $payload) {}

    public static function apply(mixed $payload = null): self
    {
        return new self(true, $payload);
    }

    public static function discard(mixed $payload = null): self
    {
        return new self(false, $payload);
    }
}
