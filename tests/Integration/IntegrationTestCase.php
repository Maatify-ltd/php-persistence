<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base class of the Integration suite. It connects to the disposable database service
 * started by scripts/integration.php and fails (never skips) when the environment is missing.
 *
 * Shared fixtures support real engine and pdo_mysql characterization and
 * the package's Transaction, Ordering and Pagination integration coverage.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var list<PDO> */
    private array $connections = [];

    /** @var list<string> */
    private array $tables = [];

    /**
     * @return non-empty-string
     */
    protected static function setting(string $name): string
    {
        $value = getenv('PHP_PERSISTENCE_IT_' . $name);
        if ($value === false || $value === '') {
            self::fail('Integration environment variable PHP_PERSISTENCE_IT_' . $name . ' is not set. Run composer test:integration.');
        }

        return $value;
    }

    protected static function engine(): string
    {
        return self::setting('ENGINE');
    }

    /**
     * Opens and tracks a connection to the disposable fixture service.
     * A null password uses the integration environment setting.
     */
    protected function connect(?string $password = null): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ];
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');
        $pdo = new PDO($dsn, self::setting('USER'), $password ?? self::setting('PASSWORD'), $options);
        $this->connections[] = $pdo;

        return $pdo;
    }

    /**
     * Creates a uniquely named InnoDB table with an integer primary key and a value column.
     * The table is dropped in tearDown.
     */
    protected function createTable(PDO $pdo, string $suffix = 't'): string
    {
        $name = 'it_' . bin2hex(random_bytes(6)) . '_' . $suffix;
        $pdo->exec('CREATE TABLE `' . $name . '` (id INT NOT NULL PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB');
        $this->tables[] = $name;

        return $name;
    }

    protected function scalar(PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<int>
     */
    protected function values(PDO $pdo, string $table): array
    {
        $statement = $pdo->query('SELECT v FROM `' . $table . '` ORDER BY id');
        self::assertNotFalse($statement);

        return array_values(array_map(
            static fn(mixed $value): int => is_numeric($value) ? (int) $value : 0,
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ));
    }

    /**
     * Executes $callback and returns the PDOException it raised, failing when none was raised.
     */
    protected static function failureOf(callable $callback): PDOException
    {
        try {
            $callback();
        } catch (PDOException $exception) {
            return $exception;
        }

        self::fail('Expected a PDOException.');
    }

    protected static function driverCode(PDOException $exception): int
    {
        $code = $exception->errorInfo[1] ?? 0;

        return is_numeric($code) ? (int) $code : 0;
    }

    protected static function sqlState(PDOException $exception): string
    {
        $state = $exception->errorInfo[0] ?? '';

        return is_string($state) ? $state : '';
    }

    /**
     * Records any transaction leak before rollback, drops the tracked fixture
     * tables and releases the connection references. Asserts the original leak
     * after cleanup so a successful rollback cannot hide it.
     */
    protected function tearDown(): void
    {
        $leaked = false;
        foreach ($this->connections as $pdo) {
            if ($pdo->inTransaction()) {
                $leaked = true;
                $pdo->rollBack();
            }
        }
        if ($this->tables !== [] && $this->connections !== []) {
            $cleaner = $this->connections[0];
            foreach ($this->tables as $table) {
                $cleaner->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
        }
        $this->connections = [];
        $this->tables = [];
        parent::tearDown();

        self::assertFalse($leaked, 'A test leaked an open transaction.');
    }
}
