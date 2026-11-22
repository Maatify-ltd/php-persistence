<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use SensitiveParameter;
use Throwable;

/** @internal Driver receiver valid only during one reserved operation. */
final class ProtectedTransactionConnection
{
    /** @var array<int, ProtectedTransactionStatement> */
    private array $statements = [];
    private ?Throwable $failure = null;
    private bool $cursorFailure = false;
    private bool $continuityFailure = false;
    private bool $sentSql = false;

    public function __construct(#[SensitiveParameter] private ?PDO $pdo) {}

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
            $this->statements[spl_object_id($guarded)] = $guarded;

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

    /**
     * Reports transaction activity only while the receiver is usable. Once a
     * driver failure is latched, savepoint cleanup sees an inactive receiver
     * even if PDO still reports the transaction active.
     */
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

    /** Latches the first driver failure for settlement, then throws the immediate error. */
    public function fail(Throwable $error, bool $cursor = false, bool $continuity = false): never
    {
        if ($this->failure === null) {
            $this->cursorFailure = $cursor;
            $this->continuityFailure = $continuity;
            $this->failure = $error;
        }
        throw $error;
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    public function cursorFailure(): bool
    {
        return $this->cursorFailure;
    }

    public function continuityFailure(): bool
    {
        return $this->continuityFailure;
    }

    public function verifyActive(?string $session): void
    {
        try {
            $driver = new ProtectedTransactionDriver($this->driver());
            $driver->inspect(
                true,
                $session ?? throw new ProtectedTransactionException('There is no verified session.'),
                closeOnFetchFailure: true,
            );
        } catch (Throwable $failure) {
            $this->fail($failure, continuity: true);
        }
    }

    public function forgetStatement(ProtectedTransactionStatement $statement): void
    {
        unset($this->statements[spl_object_id($statement)]);
    }

    public function sentSql(): bool
    {
        return $this->sentSql;
    }

    /**
     * Closes all tracked statement receivers and drops the local PDO reference
     * before the checkout releases its reservation. Cleanup failures preserve
     * the first recorded failure and cannot keep the receiver usable.
     */
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
