<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Closure;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\DTO\ProtectedTransactionSettlementDTO;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use PDOException;
use Throwable;
use WeakMap;

/** One exclusive Host delegation and one explicitly controlled write transaction. */
final class PdoProtectedTransactionCheckout
{
    /** @var WeakMap<PDO, true>|null */
    private static ?WeakMap $attached = null;
    private ?string $session = null;
    private bool $mariaDb = false;
    private bool $busy = false;
    private ?ProtectedTransactionSettlementDTO $outcome = null;

    private function __construct(
        private ?PDO $pdo,
        private readonly ProtectedTransactionCommandCatalog $catalog,
    ) {}

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

        $checkout = new self($pdo, $catalog);
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

    /**
     * @param array<array-key, mixed> $bindings
     * @return int|list<array<array-key, mixed>>
     */
    public function runRegisteredCommand(string $commandId, array $bindings = []): int|array
    {
        $command = $this->catalog->command($commandId);
        $params = $command->bind($bindings);

        return $this->run(static function (ProtectedTransactionConnection $connection) use ($command, $params): int|array {
            $statement = $connection->prepare($command->sql);
            foreach ($params as $name => $value) {
                $statement->bind($name, $value);
            }
            $statement->execute();
            if ($command->maxRows === null) {
                return $statement->rowCount();
            }

            $rows = [];
            while (($row = $statement->row()) !== false) {
                if (count($rows) === $command->maxRows) {
                    throw new ProtectedTransactionException('The protected transaction result exceeds its row bound.');
                }
                $rows[] = $row;
            }

            return $rows;
        });
    }

    /**
     * @internal Runs a package-owned Ordering operation with an expiring driver receiver.
     * @template T of int|bool
     * @param Closure(ProtectedTransactionConnection): T $operation
     * @return T
     */
    public function runOrdering(Closure $operation): int|bool
    {
        return $this->run($operation);
    }

    /**
     * @template T
     * @param Closure(ProtectedTransactionConnection): T $operation
     * @return T
     */
    private function run(Closure $operation): mixed
    {
        $session = $this->activeSession();
        $this->reserve();
        try {
            $connection = new ProtectedTransactionConnection($this->connection());
            $primary = null;
            try {
                $result = $operation($connection);
                $failure = $connection->failure();
                if ($failure !== null) {
                    throw $failure;
                }

                return $result;
            } catch (Throwable $error) {
                $primary = $error;
                throw $error;
            } finally {
                $this->finishOperation($connection, $session, $primary);
            }
        } finally {
            $this->busy = false;
        }
    }

    private function finishOperation(ProtectedTransactionConnection $connection, string $session, ?Throwable $primary): void
    {
        $connection->seal();
        $failure = $connection->failure();
        if ($failure === null && $connection->sentSql()) {
            try {
                $this->inspect(true, $session);
            } catch (Throwable $unconfirmed) {
                $failure = $unconfirmed;
            }
        }
        if ($failure === null) {
            return;
        }
        $info = $failure instanceof PDOException ? $failure->errorInfo : null;
        $lost = $this->mariaDb && ($info[0] ?? null) === 'HY000' && in_array($info[1] ?? null, [1020, '1020'], true);
        $this->settle(Disposition::DISCARD_REQUIRED, $lost ? Reason::TRANSACTION_LOST : Reason::OPERATION_UNCONFIRMED);
        if ($primary === null) {
            throw $failure;
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
        $statement = $pdo->query('SELECT CONNECTION_ID(), @@autocommit, VERSION()');
        if ($statement === false) {
            throw new ProtectedTransactionException('Unable to inspect the protected session.');
        }
        $row = $statement->fetch(PDO::FETCH_NUM);
        if (!$statement->closeCursor() || !is_array($row)
            || (!is_string($row[0] ?? null) && !is_int($row[0] ?? null))
            || preg_match('/\A[1-9][0-9]*\z/', (string) $row[0]) !== 1
            || !in_array($row[1] ?? null, [1, '1'], true)
            || !is_string($row[2] ?? null)
            || ($expected !== null && (string) $row[0] !== $expected)
            || $pdo->inTransaction() !== $active
        ) {
            throw new ProtectedTransactionException('Unable to confirm the protected session.');
        }

        if ($expected === null) {
            $this->mariaDb = stripos($row[2], 'MariaDB') !== false;
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
