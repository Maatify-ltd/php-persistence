<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\DTO\ProtectedTransactionSettlementDTO;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use Throwable;
use WeakMap;

/** One exclusive Host delegation and one explicitly controlled write transaction. */
final class PdoProtectedTransactionCheckout
{
    /** @var WeakMap<PDO, true>|null */
    private static ?WeakMap $attached = null;
    private ?string $session = null;
    private bool $busy = false;
    private ?ProtectedTransactionSettlementDTO $outcome = null;

    private function __construct(private ?PDO $pdo) {}

    public static function attachExclusive(PDO $pdo, ProtectedTransactionCommandCatalog $catalog): self
    {
        self::$attached ??= new WeakMap();
        if (isset(self::$attached[$pdo])
            || ($pdo::class !== PDO::class && $pdo::class !== Mysql::class)
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_PERSISTENT)
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== [PDOStatement::class]
        ) {
            throw new ProtectedTransactionException('The Host connection is ineligible for protected transactions.');
        }

        $checkout = new self($pdo);
        self::$attached[$pdo] = true;

        return $checkout;
    }

    public function begin(): void
    {
        if ($this->session !== null || $this->outcome !== null) {
            throw new ProtectedTransactionException('This protected checkout cannot start a transaction.');
        }
        $this->reserve();
        $reason = Reason::SERVER_QUALIFICATION_FAILED;
        try {
            $session = $this->inspect(false);
            $reason = Reason::START_UNCONFIRMED;
            $this->control('START TRANSACTION READ WRITE');
            $this->inspect(true, $session);
            $this->session = $session;
        } catch (Throwable $failure) {
            $this->settle(Disposition::DISCARD_REQUIRED, $reason);
            throw $failure;
        } finally {
            $this->busy = false;
        }
    }

    public function commit(): void
    {
        $this->finish(true);
    }

    public function rollBack(): void
    {
        $this->finish(false);
    }

    public function settlement(): ?ProtectedTransactionSettlementDTO
    {
        return $this->outcome;
    }

    public function returnForHost(): PDO
    {
        $this->reserve();
        try {
            if ($this->outcome === null && $this->session === null) {
                $this->settle(Disposition::UNCHANGED, Reason::CANCELLED_BEFORE_QUALIFICATION);
            }
            if ($this->outcome === null || $this->outcome->disposition === Disposition::DISCARD_REQUIRED) {
                throw new ProtectedTransactionException('The protected connection cannot be returned.');
            }

            $pdo = $this->connection();
            unset(self::$attached[$pdo]);
            $this->pdo = null;

            return $pdo;
        } finally {
            $this->busy = false;
        }
    }

    public function discardForHost(): void
    {
        $this->reserve();
        try {
            if ($this->outcome === null) {
                $this->settle(Disposition::DISCARD_REQUIRED, Reason::HOST_DISCARDED);
            }
            // Dropping the last reference can trigger driver-owned cleanup.
            $this->pdo = null;
        } finally {
            $this->busy = false;
        }
    }

    private function finish(bool $commit): void
    {
        $session = $this->activeSession();
        $this->reserve();
        $reason = Reason::OPERATION_UNCONFIRMED;
        try {
            $this->inspect(true, $session);
            $reason = $commit ? Reason::COMMIT_OUTCOME_UNKNOWN : Reason::ROLLBACK_UNCONFIRMED;
            $this->control($commit ? 'COMMIT AND NO CHAIN NO RELEASE' : 'ROLLBACK AND NO CHAIN NO RELEASE');
            $reason = $commit ? Reason::COMMIT_CONFIRMED_CONNECTION_UNSAFE : Reason::ROLLBACK_UNCONFIRMED;
            $this->inspect(false, $session);
            $this->settle(Disposition::REUSABLE, $commit ? Reason::COMMITTED : Reason::ROLLED_BACK);
        } catch (Throwable $failure) {
            $this->settle(Disposition::DISCARD_REQUIRED, $reason);
            throw $failure;
        } finally {
            $this->busy = false;
        }
    }

    private function reserve(): void
    {
        if ($this->busy || $this->pdo === null) {
            throw new ProtectedTransactionException('The protected checkout is unavailable.');
        }
        $this->busy = true;
    }

    private function activeSession(): string
    {
        if ($this->session === null || $this->outcome !== null) {
            throw new ProtectedTransactionException('There is no active protected transaction.');
        }

        return $this->session;
    }

    private function connection(): PDO
    {
        return $this->pdo ?? throw new ProtectedTransactionException('The protected connection is unavailable.');
    }

    private function inspect(bool $active, ?string $expected = null): string
    {
        $pdo = $this->connection();
        $statement = $pdo->query('SELECT CONNECTION_ID(), @@autocommit');
        if ($statement === false) {
            throw new ProtectedTransactionException('Unable to inspect the protected session.');
        }
        $row = $statement->fetch(PDO::FETCH_NUM);
        if (!$statement->closeCursor() || !is_array($row)
            || (!is_string($row[0] ?? null) && !is_int($row[0] ?? null))
            || preg_match('/\A[1-9][0-9]*\z/', (string) $row[0]) !== 1
            || !in_array($row[1] ?? null, [1, '1'], true)
            || ($expected !== null && (string) $row[0] !== $expected)
            || $pdo->inTransaction() !== $active
        ) {
            throw new ProtectedTransactionException('Unable to confirm the protected session.');
        }

        return (string) $row[0];
    }

    private function control(string $sql): void
    {
        if ($this->connection()->exec($sql) === false) {
            throw new ProtectedTransactionException('Unable to confirm the protected transaction command.');
        }
    }

    private function settle(Disposition $disposition, Reason $reason): void
    {
        $this->outcome = new ProtectedTransactionSettlementDTO($disposition, $reason);
        $this->session = null;
    }

    private function __clone() {}

    /** @return array<array-key, mixed> */
    public function __serialize(): array
    {
        throw new ProtectedTransactionException('A protected checkout cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new ProtectedTransactionException('A protected checkout cannot be restored.');
    }
}
