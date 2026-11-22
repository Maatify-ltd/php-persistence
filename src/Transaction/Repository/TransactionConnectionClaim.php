<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use SensitiveParameter;
use WeakMap;

/**
 * Claims one PDO object across the Protected and Verified checkouts. Safe
 * handoff releases the claim. Retirement keeps a claim until that object is
 * destroyed, so a retained reference cannot be attached again.
 *
 * @internal
 */
final class TransactionConnectionClaim
{
    /** @var WeakMap<PDO, true>|null */
    private static ?WeakMap $claims = null;

    public static function exists(#[SensitiveParameter] PDO $pdo): bool
    {
        return isset(self::$claims[$pdo]);
    }

    /** Refuses an already delegated PDO object before granting authority. */
    public static function acquire(#[SensitiveParameter] PDO $pdo): void
    {
        self::$claims ??= new WeakMap();
        if (self::exists($pdo)) {
            throw new ProtectedTransactionException('The Host connection is already delegated.');
        }
        self::$claims[$pdo] = true;
    }

    /** Releases the object claim only for a confirmed handoff. */
    public static function release(#[SensitiveParameter] PDO $pdo): void
    {
        unset(self::$claims[$pdo]);
    }
}
