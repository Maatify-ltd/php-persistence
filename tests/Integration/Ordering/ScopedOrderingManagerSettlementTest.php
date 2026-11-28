<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\FaultInjectingPdo;
use Maatify\PhpPersistence\Tests\Integration\Support\ObservedOrderingUpdateStatement;
use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use PDO;
use PDOException;
use PDOStatement;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScopedOrderingManagerSettlementTest extends IntegrationTestCase
{
    /** @return iterable<string, array{bool, ?string, bool}> */
    public static function settlementOutcomes(): iterable
    {
        yield 'owned rollback acknowledged' => [false, null, false];
        yield 'Host undo and release acknowledged' => [true, null, false];
        foreach (['ROLLBACK', 'ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT'] as $control) {
            $hostOwns = $control !== 'ROLLBACK';
            yield "$control returns false" => [$hostOwns, $control, false];
            yield "$control throws" => [$hostOwns, $control, true];
        }
    }

    #[DataProvider('settlementOutcomes')]
    public function testRawZeroRowMoveRequiresConfirmedSettlement(bool $hostOwns, ?string $control, bool $throws): void
    {
        $observer = $this->connect();
        $table = 'it_' . bin2hex(random_bytes(6)) . '_order_settlement';
        $observer->exec("CREATE TABLE `$table` (id INT PRIMARY KEY, scope_id INT NOT NULL, position INT NOT NULL, marker INT NOT NULL DEFAULT 0, INDEX (scope_id, position)) ENGINE=InnoDB");
        $writer = null;

        try {
            $observer->exec("INSERT INTO `$table` (id, scope_id, position) VALUES (1, 10, 1), (2, 10, 2), (3, 10, 3), (4, 20, 1)");
            $this->keepTargetPosition($table);

            $writer = $this->faultConnection();
            $affectedRows = [];
            $observeUpdate = static function (int $count) use (&$affectedRows): void {
                $affectedRows[] = $count;
            };
            self::assertTrue($writer->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ObservedOrderingUpdateStatement::class, [$observeUpdate]]));
            if ($hostOwns) {
                self::assertTrue($writer->beginTransaction());
                self::assertSame(1, $writer->exec("UPDATE `$table` SET marker = 1 WHERE id = 4"));
            }

            $injected = $throws ? new PDOException('Injected Ordering settlement failure.') : null;
            if ($control === 'ROLLBACK') {
                $writer->failNextRollback($injected ?? false);
            } elseif ($control !== null) {
                $writer->failExec('/\A' . $control . ' /', $injected ?? 'false');
            }

            $result = null;
            $failure = null;
            $config = new ScopedOrderingConfig(
                table: $table,
                orderColumn: 'position',
                scopeColumn: 'scope_id',
                deletedAtColumn: null,
            );
            try {
                $result = (new ScopedOrderingManager())->moveWithinScope($writer, $config, 10, 3, 1);
            } catch (PDOException|TransactionExecutionException $exception) {
                $failure = $exception;
            }

            self::assertSame([2, 0], $affectedRows, 'The real range shift changes two rows and the target UPDATE changes zero.');
            $active = $hostOwns || $control === 'ROLLBACK';
            $shiftsPending = $control === 'ROLLBACK' || $control === 'ROLLBACK TO SAVEPOINT';
            self::assertSame($active, $writer->inTransaction());
            self::assertSame($shiftsPending ? [2, 3, 3, 1] : [1, 2, 3, 1], $this->positions($writer, $table));
            self::assertSame([1, 2, 3, 1], $this->positions($observer, $table));
            self::assertSame($hostOwns ? '1' : '0', $this->scalar($writer, "SELECT marker FROM `$table` WHERE id = 4"));
            self::assertSame('0', $this->scalar($observer, "SELECT marker FROM `$table` WHERE id = 4"));

            if ($hostOwns) {
                $this->assertLocalControlSequence($writer->savepointStatements(), $control === 'ROLLBACK TO SAVEPOINT');
            } else {
                self::assertSame([], $writer->savepointStatements());
            }

            if ($control === null) {
                self::assertNull($failure);
                self::assertFalse($result);
            } else {
                self::assertNull($result, 'Failed settlement cannot return the ordinary false result.');
                if ($injected !== null) {
                    self::assertSame($injected, $failure);
                } else {
                    self::assertInstanceOf(TransactionExecutionException::class, $failure);
                }
            }

            $hostCommitsMarker = $hostOwns && !$shiftsPending;
            if ($hostCommitsMarker) {
                self::assertTrue($writer->commit());
            } elseif ($active) {
                self::assertTrue($writer->rollBack());
            }

            self::assertFalse($writer->inTransaction());
            self::assertSame([1, 2, 3, 1], $this->positions($observer, $table));
            self::assertSame($hostCommitsMarker ? '1' : '0', $this->scalar($observer, "SELECT marker FROM `$table` WHERE id = 4"));
        } finally {
            try {
                if ($writer?->inTransaction()) {
                    $writer->exec('ROLLBACK');
                }
            } finally {
                $writer = null;
                $observer->exec("DROP TABLE IF EXISTS `$table`");
            }
        }
    }

    private function keepTargetPosition(string $table): void
    {
        // Only fixture DDL uses the privileged account. The move uses the test application account.
        $admin = new PDO(self::dsn(), 'root', self::setting('ADMIN_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        $admin->exec("CREATE TRIGGER `{$table}_target` BEFORE UPDATE ON `$table` FOR EACH ROW SET NEW.position = IF(OLD.id = 3, OLD.position, NEW.position)");
    }

    private function faultConnection(): FaultInjectingPdo
    {
        return new FaultInjectingPdo(self::dsn(), self::setting('USER'), self::setting('PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
            Mysql::ATTR_FOUND_ROWS => false,
        ]);
    }

    private static function dsn(): string
    {
        return 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');
    }

    /** @return list<int> */
    private function positions(PDO $pdo, string $table): array
    {
        $statement = $pdo->query("SELECT position FROM `$table` ORDER BY id");
        self::assertInstanceOf(PDOStatement::class, $statement);

        $positions = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $position) {
            self::assertIsNumeric($position);
            $positions[] = (int) $position;
        }

        return $positions;
    }

    /** @param list<string> $statements */
    private function assertLocalControlSequence(array $statements, bool $undoFailed): void
    {
        self::assertCount($undoFailed ? 2 : 3, $statements);
        $savepoint = $statements[0];
        self::assertMatchesRegularExpression('/\ASAVEPOINT maatify_persistence_sp_[0-9a-f]{32}\z/', $savepoint);
        self::assertSame('ROLLBACK TO ' . $savepoint, $statements[1]);
        if (!$undoFailed) {
            self::assertSame('RELEASE ' . $savepoint, $statements[2]);
        }
    }
}
