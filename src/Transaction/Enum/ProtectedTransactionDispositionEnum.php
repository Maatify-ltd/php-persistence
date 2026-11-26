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
    case UNCHANGED;
    /**
     * Released resources and the acknowledged end were confirmed on the same idle session.
     */
    case REUSABLE;
    /**
     * The checkout fences handoff. The Host disposes of the connection and reconciles uncertain work.
     */
    case DISCARD_REQUIRED;
}
