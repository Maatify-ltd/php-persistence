<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use Throwable;

/** @internal Driver receiver valid only during one reserved operation. */
final class ProtectedTransactionConnection
{
    /** @var list<ProtectedTransactionStatement> */
    private array $statements = [];
    private ?Throwable $failure = null;
    private bool $sentSql = false;

    public function __construct(private ?PDO $pdo) {}

    public function prepare(string $sql): ProtectedTransactionStatement
    {
        $pdo = $this->driver();
        $this->sentSql = true;
        try {
            $statement = $pdo->prepare($sql);
            if ($statement === false) {
                throw new ProtectedTransactionException('Unable to prepare the protected transaction statement.');
            }
            $guarded = new ProtectedTransactionStatement($this, $statement);
            $this->statements[] = $guarded;

            return $guarded;
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }

    public function control(string $sql): void
    {
        $pdo = $this->driver();
        $this->sentSql = true;
        try {
            if ($pdo->exec($sql) === false) {
                throw new ProtectedTransactionException('Unable to confirm the protected transaction command.');
            }
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }

    public function inTransaction(): bool
    {
        if ($this->pdo === null || $this->failure !== null) {
            return false;
        }
        try {
            return $this->pdo->inTransaction();
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }

    public function ensureAvailable(): void
    {
        $this->driver();
    }

    private function driver(): PDO
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->pdo ?? throw new ProtectedTransactionException('The protected driver scope is closed.');
    }

    public function fail(Throwable $error): never
    {
        $this->failure ??= $error;
        throw $error;
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    public function sentSql(): bool
    {
        return $this->sentSql;
    }

    public function seal(): void
    {
        try {
            foreach ($this->statements as $statement) {
                try {
                    $statement->seal();
                } catch (Throwable $failure) {
                    $this->failure ??= $failure;
                }
            }
        } finally {
            $this->statements = [];
            $this->pdo = null;
        }
    }
}
