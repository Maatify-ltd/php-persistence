<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Proof;

/**
 * The proof callback's explicit Apply or Discard intent and independent
 * result payload. The selected intent, rather than payload truthiness,
 * controls how the fixture resolves the operation.
 */
final readonly class Decision
{
    public function __construct(public Intent $intent, public mixed $value) {}
}
