<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Ordering\Repository;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedStatement;
use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises raw moveWithinScope() with scripted PDO to verify owned rollback
 * and Host savepoint control sequences. Returning false requires confirmed
 * settlement. A cleanup failure preserves the original Throwable.
 *
 * SavepointScopeTest covers the shared savepoint helper in isolation.
 * ScopedOrderingManagerSettlementTest exercises the public operation on
 * InnoDB. It uses the disposable admin for trigger setup only, then runs the
 * real shift and zero-row target UPDATE through the application PDO. It
 * verifies affected rows, Host ownership, and pending versus committed data.
 */
final class ScopedOrderingManagerSavepointWiringTest extends TestCase
{
    /** @return iterable<string, array{PDOException|false}> */
    public static function settlementFailures(): iterable
    {
        yield 'false' => [false];
        yield 'throws' => [new PDOException('Cleanup failed.')];
    }

    /** @return iterable<string, array{string, PDOException|false}> */
    public static function hostSettlementFailures(): iterable
    {
        foreach (self::settlementFailures() as $name => [$failure]) {
            yield "rollback to $name" => ['ROLLBACK TO SAVEPOINT', $failure];
            yield "release $name" => ['RELEASE SAVEPOINT', $failure];
        }
    }

    #[DataProvider('settlementFailures')]
    public function testOwnedRejectedMoveExposesRollbackFailure(PDOException|false $failure): void
    {
        $pdo = new OrderingScriptedPdo($this->moveStatements(new OrderingScriptedStatement()), participating: false, rollbackResult: $failure);
        try {
            (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);
            self::fail('A failed rollback must not return a normal result.');
        } catch (PDOException|TransactionExecutionException $error) {
            if ($failure === false) {
                self::assertInstanceOf(TransactionExecutionException::class, $error);
            } else {
                self::assertSame($failure, $error);
            }
        }

        self::assertSame(1, $pdo->beginCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollbackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    public function testOwnedMissingTargetWithoutAnActiveTransactionCannotConfirmRollback(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]),
            new OrderingScriptedStatement(fetchColumnQueue: [false]),
        );

        $this->expectException(TransactionExecutionException::class);

        (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);
    }

    public function testRollbackAcknowledgementWithAnActiveOwnedConnectionDoesNotReturnFalse(): void
    {
        $active = false;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$active): bool {
            return $active;
        });
        $pdo->expects(self::once())->method('beginTransaction')->willReturnCallback(static function () use (&$active): bool {
            $active = true;

            return true;
        });
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]),
            new OrderingScriptedStatement(fetchColumnQueue: [false]),
        );

        $this->expectException(TransactionExecutionException::class);

        (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);
    }

    #[DataProvider('hostSettlementFailures')]
    public function testHostRejectedMoveExposesLocalSettlementFailure(string $control, PDOException|false $failure): void
    {
        $failsRelease = $control === 'RELEASE SAVEPOINT';
        $results = $failsRelease ? [0, 0, $failure] : [0, $failure];
        $pdo = new OrderingScriptedPdo($this->moveStatements(new OrderingScriptedStatement()), controlResults: $results);
        try {
            (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);
            self::fail('A failed local settlement must not return a normal result.');
        } catch (PDOException|TransactionExecutionException $error) {
            if ($failure === false) {
                self::assertInstanceOf(TransactionExecutionException::class, $error);
            } else {
                self::assertSame($failure, $error);
            }
        }

        self::assertSame(0, $pdo->beginCalls + $pdo->commitCalls + $pdo->rollbackCalls);
        self::assertTrue($pdo->inTransaction());
        self::assertCount($failsRelease ? 3 : 2, $pdo->executedControlStatements);
        self::assertStringStartsWith('SAVEPOINT ', $pdo->executedControlStatements[0]);
        self::assertStringStartsWith('ROLLBACK TO SAVEPOINT ', $pdo->executedControlStatements[1]);
        if ($failsRelease) {
            self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedControlStatements[2]);
        }
    }

    #[DataProvider('settlementFailures')]
    public function testOwnedMoveKeepsThePrimaryErrorWhenRollbackFails(PDOException|false $failure): void
    {
        $primary = new PDOException('Target write failed.');
        $target = $this->createMock(PDOStatement::class);
        $target->expects(self::once())->method('execute')->willThrowException($primary);
        $target->method('closeCursor')->willReturn(true);
        $pdo = new OrderingScriptedPdo($this->moveStatements($target), participating: false, rollbackResult: $failure);

        $this->expectExceptionObject($primary);
        try {
            (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);
        } catch (PDOException $error) {
            self::assertSame($primary, $error);

            throw $error;
        } finally {
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertSame(0, $pdo->commitCalls);
        }
    }

    #[DataProvider('hostSettlementFailures')]
    public function testHostMoveKeepsThePrimaryErrorWhenLocalSettlementFails(string $control, PDOException|false $failure): void
    {
        $primary = new PDOException('Target write failed.');
        $target = $this->createMock(PDOStatement::class);
        $target->expects(self::once())->method('execute')->willThrowException($primary);
        $target->method('closeCursor')->willReturn(true);
        $failsRelease = $control === 'RELEASE SAVEPOINT';
        $pdo = new OrderingScriptedPdo($this->moveStatements($target), controlResults: $failsRelease ? [0, 0, $failure] : [0, $failure]);

        $this->expectExceptionObject($primary);
        try {
            (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);
        } catch (PDOException $error) {
            self::assertSame($primary, $error);

            throw $error;
        } finally {
            self::assertSame(0, $pdo->beginCalls + $pdo->commitCalls + $pdo->rollbackCalls);
            self::assertTrue($pdo->inTransaction());
            self::assertCount($failsRelease ? 3 : 2, $pdo->executedControlStatements);
        }
    }

    /** @return list<PDOStatement> */
    private function moveStatements(PDOStatement $target): array
    {
        return [
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]),
            new OrderingScriptedStatement(fetchColumnQueue: [5]),
            new OrderingScriptedStatement(),
            $target,
        ];
    }

    public function testSuccessfulTargetWriteCallsReleaseOrUndo(): void
    {
        $pdo = new OrderingScriptedPdo([
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]), // scope lock: one row at order 3, then end of cursor
            new OrderingScriptedStatement(fetchColumnQueue: [5]),        // current order of the target row
            new OrderingScriptedStatement(),                             // shift UPDATE
            new OrderingScriptedStatement(rowCount: 1),                  // target UPDATE: 1 row changed
        ]);

        $result = (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);

        self::assertTrue($result);
        self::assertCount(2, $pdo->executedControlStatements);
        self::assertMatchesRegularExpression(
            '/^SAVEPOINT (maatify_persistence_sp_[0-9a-f]{32})$/',
            $pdo->executedControlStatements[0],
        );
        $name = substr($pdo->executedControlStatements[0], strlen('SAVEPOINT '));
        self::assertSame('RELEASE SAVEPOINT ' . $name, $pdo->executedControlStatements[1]);
    }

    public function testTargetWriteReportingNoAffectedRowsAfterARealShiftCallsUndoOrFailAndReturnsFalse(): void
    {
        $pdo = new OrderingScriptedPdo([
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]), // scope lock: one row at order 3, then end of cursor
            new OrderingScriptedStatement(fetchColumnQueue: [5]),        // current order of the target row
            new OrderingScriptedStatement(),                             // shift UPDATE
            new OrderingScriptedStatement(rowCount: 0),                  // target UPDATE: zero rows changed (the hazard)
        ]);

        $result = (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);

        self::assertFalse($result);
        self::assertCount(3, $pdo->executedControlStatements);
        self::assertMatchesRegularExpression(
            '/^SAVEPOINT (maatify_persistence_sp_[0-9a-f]{32})$/',
            $pdo->executedControlStatements[0],
        );
        $name = substr($pdo->executedControlStatements[0], strlen('SAVEPOINT '));
        self::assertSame('ROLLBACK TO SAVEPOINT ' . $name, $pdo->executedControlStatements[1]);
        self::assertSame('RELEASE SAVEPOINT ' . $name, $pdo->executedControlStatements[2]);
    }

    public function testOwnedPathTakesNoSavepointAtAllRegardlessOfTheTargetWriteOutcome(): void
    {
        $pdo = new OrderingScriptedPdo([
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]),
            new OrderingScriptedStatement(fetchColumnQueue: [5]),
            new OrderingScriptedStatement(),
            new OrderingScriptedStatement(rowCount: 0),
        ], participating: false);

        $result = (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(table: 'items'), null, 5, 2);

        self::assertFalse($result);
        self::assertSame([], $pdo->executedControlStatements, 'The owned path never issues a savepoint statement.');
        self::assertFalse($pdo->inTransaction(), 'The owned transaction is rolled back and left closed.');
    }
}
