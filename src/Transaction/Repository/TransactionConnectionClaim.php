<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use SensitiveParameter;
use WeakMap;

/** @internal Exclusive PDO-object claims shared only within Transaction. */
final class TransactionConnectionClaim
{
    /** @var WeakMap<PDO, true>|null */
    private static ?WeakMap $claims = null;

    public static function exists(#[SensitiveParameter] PDO $pdo): bool
    {
        return isset(self::$claims[$pdo]);
    }

    public static function acquire(#[SensitiveParameter] PDO $pdo): void
    {
        self::$claims ??= new WeakMap();
        if (self::exists($pdo)) {
            throw new ProtectedTransactionException('The Host connection is already delegated.');
        }
        self::$claims[$pdo] = true;
    }

    public static function release(#[SensitiveParameter] PDO $pdo): void
    {
        unset(self::$claims[$pdo]);
    }
}
