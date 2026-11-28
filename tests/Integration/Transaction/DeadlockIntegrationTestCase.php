<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Real-database base for the deadlock-retry tests (B-12). provokeDeadlockAsVictim()
 * makes the connection under test the victim of a genuine InnoDB deadlock
 * (driver code 1213, SQLSTATE 40001) against a DeadlockPartner process on its
 * own connection; no error is simulated. Rows 1 ("lock-a") and 2 ("lock-b"),
 * inserted first into the fresh table created by setUp(), are the contended
 * rows; later inserts record payloads committed by the test.
 */
abstract class DeadlockIntegrationTestCase extends IntegrationTestCase
{
    protected const DEADLOCK_DRIVER_CODE = 1213;
    protected const DEADLOCK_SQLSTATE = '40001';
    protected const PARTNER_READ_TIMEOUT_SECONDS = 30.0;

    private PDO $controlPdo;

    private string $table;

    /** @var list<PartnerProcess> */
    private array $partners = [];

    protected function setUp(): void
    {
        $this->controlPdo = $this->connect();
        $this->table = $this->createPayloadTable($this->controlPdo);
        $this->insert($this->controlPdo, 'lock-a');
        $this->insert($this->controlPdo, 'lock-b');
        $this->partners = [];
    }

    protected function tearDown(): void
    {
        $this->stopPartners();
        $this->controlPdo->exec('DROP TABLE IF EXISTS `' . $this->table . '`');
        parent::tearDown();
    }

    /**
     * Inside the current transaction of $victim: lock row 1, let a heavier
     * partner (holding row 2) queue for row 1, then request row 2. The server
     * detects the cycle and rolls back the victim's lighter transaction.
     * Returns the genuine PDOException the server raised.
     */
    protected function provokeDeadlockAsVictim(PDO $victim): PDOException
    {
        return $this->provokeDeadlockUsing(fn(int $id) => $this->lockRow($victim, $id));
    }

    /** @param callable(int): void $lockRow */
    protected function provokeDeadlockUsing(callable $lockRow): PDOException
    {
        $partner = PartnerProcess::deadlockPartner($this->table, 1, 2);
        $this->partners[] = $partner;

        $ready = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
        self::assertStringStartsWith('READY ', $ready);

        $lockRow(1);
        $partner->writeLine('go');
        $this->awaitBlockedLockingRead(substr($ready, strlen('READY ')));

        try {
            $lockRow(2);
        } catch (PDOException $exception) {
            self::assertSame(self::DEADLOCK_SQLSTATE, $exception->errorInfo[0] ?? null, $exception->getMessage());
            self::assertSame(self::DEADLOCK_DRIVER_CODE, $exception->errorInfo[1] ?? null, $exception->getMessage());

            return $exception;
        }

        self::fail('The victim connection was expected to be chosen as the deadlock victim.');
    }

    protected function insert(PDO $pdo, string $payload): void
    {
        $statement = $pdo->prepare('INSERT INTO `' . $this->table . '` (`payload`) VALUES (:payload)');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['payload' => $payload]);
    }

    /** Kills the server session of $pdo from the control connection (same database user). */
    protected function killConnection(PDO $pdo): void
    {
        $id = $pdo->query('SELECT CONNECTION_ID()');
        self::assertInstanceOf(PDOStatement::class, $id);
        $connectionId = $id->fetchColumn();
        self::assertTrue(is_int($connectionId) || is_string($connectionId));
        $this->controlPdo->exec('KILL ' . (int) $connectionId);
        usleep(200_000);
    }

    /**
     * A connection outside IntegrationTestCase::connect()'s leaked-transaction
     * tracking, for a connection this test deliberately drives into a
     * server-killed or deadlock-victim state and never cleans up: the
     * documented staleness under test (B-12, PDO::inTransaction() following
     * the last successful reply) is the behavior being proven, not a leak in
     * this test.
     */
    protected function unmonitoredConnection(): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ];
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');

        return new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'), $options);
    }

    /**
     * Payloads other than the two contended lock rows, as seen by $pdo.
     *
     * @return list<string>
     */
    protected function payloadsSeenBy(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT `payload` FROM `' . $this->table . '` WHERE `id` > 2 ORDER BY `id`');
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values(array_map(
            static function (mixed $payload): string {
                self::assertIsString($payload);

                return $payload;
            },
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ));
    }

    /**
     * Committed payloads other than the two contended lock rows, read on the control connection.
     *
     * @return list<string>
     */
    protected function committedPayloads(): array
    {
        return $this->payloadsSeenBy($this->controlPdo);
    }

    /**
     * Waits for every partner started by the test and returns their final reports.
     *
     * @return list<string>
     */
    protected function partnerReports(): array
    {
        $reports = [];
        foreach ($this->partners as $partner) {
            $reports[] = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
        }

        $this->stopPartners();

        return $reports;
    }

    private function createPayloadTable(PDO $pdo): string
    {
        $name = 'it_' . bin2hex(random_bytes(6)) . '_deadlock';
        $pdo->exec(
            'CREATE TABLE `' . $name . '` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`payload` VARCHAR(128) NOT NULL, '
                . 'PRIMARY KEY (`id`)'
                . ') ENGINE=InnoDB',
        );

        return $name;
    }

    private function lockRow(PDO $pdo, int $id): void
    {
        $statement = $pdo->prepare($this->lockingReadSql());
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['id' => $id]);
        $statement->fetchAll();
    }

    protected function lockingReadSql(): string
    {
        return 'SELECT `id` FROM `' . $this->table . '` WHERE `id` = :id FOR UPDATE';
    }

    /**
     * Waits until the partner connection is stuck in its locking read, observed
     * on the control connection (SHOW FULL PROCESSLIST lists the threads of the
     * same database user without extra privileges).
     */
    private function awaitBlockedLockingRead(string $connectionId): void
    {
        $deadline = microtime(true) + 15;

        do {
            $rows = $this->controlPdo->query('SHOW FULL PROCESSLIST');
            self::assertInstanceOf(PDOStatement::class, $rows);

            foreach ($rows->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = $row['Id'] ?? null;
                $info = $row['Info'] ?? null;
                $time = $row['Time'] ?? null;

                if (
                    is_scalar($id) && (string) $id === $connectionId
                    && is_string($info) && str_contains($info, 'FOR UPDATE')
                    && is_numeric($time) && (int) $time >= 0
                ) {
                    return;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new AssertionFailedError('The deadlock partner never blocked on its locking read.');
    }

    private function stopPartners(): void
    {
        $failure = null;

        foreach ($this->partners as $partner) {
            try {
                $partner->stop();
            } catch (AssertionFailedError $error) {
                $failure ??= $error;
            }
        }

        $this->partners = [];

        if ($failure !== null) {
            throw $failure;
        }
    }
}
