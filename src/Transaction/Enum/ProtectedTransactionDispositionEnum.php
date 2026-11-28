<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Enum;

/**
 * Connection disposition for a write checkout. It governs whether the same
 * PDO can be returned to the Host and does not certify every transaction outcome.
 */
enum ProtectedTransactionDispositionEnum
{
    /**
     * An untouched attachment can be cancelled without a server qualification probe.
     */
    case Unchanged;
    /**
     * Released resources and the acknowledged end were confirmed on the same idle session.
     */
    case Reusable;
    /**
     * The checkout fences handoff. The Host disposes of the connection and reconciles uncertain work.
     */
    case DiscardRequired;

    /** @internal Stable code for settlement JSON. */
    public function settlementCode(): string
    {
        return match ($this) {
            self::Unchanged => 'UNCHANGED',
            self::Reusable => 'REUSABLE',
            self::DiscardRequired => 'DISCARD_REQUIRED',
        };
    }
}
