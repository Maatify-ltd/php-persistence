<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use SensitiveParameter;
use Throwable;

/** @internal Driver operations used under an exclusive write checkout reservation. */
final readonly class ProtectedTransactionDriver
{
    public const START = 'START TRANSACTION READ WRITE';
    public const COMMIT = 'COMMIT AND NO CHAIN NO RELEASE';
    public const ROLLBACK = 'ROLLBACK AND NO CHAIN NO RELEASE';

    public function __construct(#[SensitiveParameter] private PDO $pdo) {}

    public static function isEligible(#[SensitiveParameter] PDO $pdo): bool
    {
        return ($pdo::class === PDO::class || $pdo::class === Mysql::class)
            && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            && !$pdo->getAttribute(PDO::ATTR_PERSISTENT)
            && $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
            && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class];
    }

    /** @return array{id: string, mariaDb: bool} */
    public function inspect(bool $active, ?string $expected, bool $closeOnFetchFailure = false): array
    {
        $statement = $this->pdo->query('SELECT CONNECTION_ID(), @@autocommit, VERSION()');
        if ($statement === false) {
            throw new ProtectedTransactionException('Unable to inspect the protected session.');
        }
        try {
            $row = $statement->fetch(PDO::FETCH_NUM);
        } catch (Throwable $primary) {
            if ($closeOnFetchFailure) {
                try {
                    $statement->closeCursor();
                } catch (Throwable) {
                    // The original probe failure remains primary.
                }
            }

            throw $primary;
        }

        if (!$statement->closeCursor() || !is_array($row)
            || (!is_string($row[0] ?? null) && !is_int($row[0] ?? null))
            || preg_match('/\A[1-9][0-9]*\z/', (string) $row[0]) !== 1
            || !in_array($row[1] ?? null, [1, '1'], true)
            || !is_string($row[2] ?? null)
            || ($expected !== null && (string) $row[0] !== $expected)
            || $this->pdo->inTransaction() !== $active
        ) {
            throw new ProtectedTransactionException('Unable to confirm the protected session.');
        }

        return ['id' => (string) $row[0], 'mariaDb' => stripos($row[2], 'MariaDB') !== false];
    }

    public function start(): void
    {
        $this->control(self::START);
    }

    public function end(bool $commit): void
    {
        $this->control($commit ? self::COMMIT : self::ROLLBACK);
    }

    public function control(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new ProtectedTransactionException('Unable to confirm the protected transaction command.');
        }
    }
}
