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

    /** Checks the shared local PDO baseline. Each checkout qualifies its own session before work. */
    public static function isEligible(#[SensitiveParameter] PDO $pdo): bool
    {
        return ($pdo::class === PDO::class || $pdo::class === Mysql::class)
            && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            && !$pdo->getAttribute(PDO::ATTR_PERSISTENT)
            && $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
            && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class];
    }

    /**
     * Checks the session, enabled autocommit and the driver's transaction
     * state. Session ID and activity support continuity checks under
     * exclusive custody. They cannot detect a replaced transaction through an
     * external alias.
     *
     * @param bool $active Expected transaction state at the time of the probe.
     * @param ?string $expected Qualified session ID to match. Null accepts a valid observed ID for first qualification.
     * @param bool $closeOnFetchFailure Whether to attempt cursor cleanup after a fetch throws. The original throwable still escapes.
     * @return array{id: string, mariaDb: bool} Observed session ID and engine classification.
     * @throws ProtectedTransactionException The probe or the expected state could not be confirmed and the driver did not throw.
     */
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

    /** Requests one read-write START. The caller checks activity after acknowledgement. */
    public function start(): void
    {
        $this->control(self::START);
    }

    /** Requests one commit or rollback, with chaining and disconnect disabled. */
    public function end(bool $commit): void
    {
        $this->control($commit ? self::COMMIT : self::ROLLBACK);
    }

    /** Requires an acknowledgement of trusted control SQL and preserves any driver throwable. */
    public function control(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new ProtectedTransactionException('Unable to confirm the protected transaction command.');
        }
    }
}
