<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Ordering\Repository;

use Closure;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedStatement;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopedOrderingManagerExecutionFailureTest extends TestCase
{
    public function testPrepareFailureIsReportedBeforeExecutingAnOrderingRead(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn(false);

        $this->expectException(OrderingExecutionException::class);
        $this->expectExceptionMessage('Failed to prepare the ordering statement for getNextPosition.');

        (new ScopedOrderingManager())->getNextPosition($pdo, new ScopedOrderingConfig('items'));
    }

    public function testExecuteFailureDoesNotReadAResult(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(false);
        $statement->expects(self::never())->method('fetchColumn');
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);

        $this->expectException(OrderingExecutionException::class);
        $this->expectExceptionMessage('Failed to execute the ordering statement for getNextPosition.');

        (new ScopedOrderingManager())->getNextPosition($pdo, new ScopedOrderingConfig('items'));
    }

    public function testAggregateMustReturnARowEvenWithSuccessfulSqlstate(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn(new OrderingScriptedStatement(fetchColumnQueue: [false]));

        $this->expectException(OrderingExecutionException::class);
        $this->expectExceptionMessage('The next-position aggregate did not return a row.');

        (new ScopedOrderingManager())->getNextPosition($pdo, new ScopedOrderingConfig('items'));
    }

    /** @param Closure(ScopedOrderingManager, PDO, ScopedOrderingConfig): (int|bool) $read */
    #[DataProvider('reads')]
    public function testFalseFetchWithErrorStateFailsAndClosesCursor(Closure $read): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->expects(self::once())->method('fetchColumn')->willReturn(false);
        $statement->expects(self::once())->method('errorCode')->willReturn('HY000');
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);

        $this->expectException(OrderingExecutionException::class);
        $this->expectExceptionMessage('Failed to fetch the ordering result');

        $read(new ScopedOrderingManager(), $pdo, new ScopedOrderingConfig('items'));
    }

    /** @return iterable<string, array{Closure(ScopedOrderingManager, PDO, ScopedOrderingConfig): (int|bool)}> */
    public static function reads(): iterable
    {
        yield 'next position' => [
            static fn(ScopedOrderingManager $manager, PDO $pdo, ScopedOrderingConfig $config): int => $manager->getNextPosition($pdo, $config),
        ];
        yield 'locking next position' => [
            static fn(ScopedOrderingManager $manager, PDO $pdo, ScopedOrderingConfig $config): int => $manager->getNextPositionForUpdate($pdo, $config),
        ];
        yield 'existence' => [
            static fn(ScopedOrderingManager $manager, PDO $pdo, ScopedOrderingConfig $config): bool => $manager->rowExistsInScope($pdo, $config, null, 1),
        ];
    }

    public function testCloseFailureStopsRepeatedLockingRead(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->expects(self::once())->method('fetchColumn')->willReturn(3);
        $statement->expects(self::once())->method('closeCursor')->willReturn(false);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);

        $this->expectException(OrderingExecutionException::class);
        $this->expectExceptionMessage('Failed to close the ordering result for getNextPositionForUpdate.');

        (new ScopedOrderingManager())->getNextPositionForUpdate($pdo, new ScopedOrderingConfig('items'));
    }

    public function testScopeFetchFailureClosesCursorBeforeRollbackAndPreservesOriginalException(): void
    {
        $failure = new PDOException('Original read failure.');
        $cleanupSteps = [];
        $scopeRead = $this->createMock(PDOStatement::class);
        $scopeRead->expects(self::once())->method('execute')->willReturn(true);
        $scopeRead->expects(self::once())->method('fetchColumn')->with(1)->willThrowException($failure);
        $scopeRead->expects(self::once())->method('closeCursor')->willReturnCallback(static function () use (&$cleanupSteps): bool {
            $cleanupSteps[] = 'close cursor';
            throw new PDOException('Secondary cleanup failure.');
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false, false, true);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('prepare')->willReturn($scopeRead);
        $pdo->expects(self::once())->method('rollBack')->willReturnCallback(static function () use (&$cleanupSteps): bool {
            $cleanupSteps[] = 'rollback';

            return true;
        });

        try {
            (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig('items'), null, 1, 2);
            self::fail('Expected the original PDO exception.');
        } catch (PDOException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(['close cursor', 'rollback'], $cleanupSteps);
    }

    #[DataProvider('failedWrites')]
    public function testFailedWritePreventsCommitAndRollsBack(
        string $operation,
        bool $callerOwned,
        int $currentOrder,
        int $newOrder,
    ): void {
        $badWrite = $this->createMock(PDOStatement::class);
        $badWrite->expects(self::once())->method('execute')->willReturn(false);
        $badWrite->expects(self::never())->method('rowCount');
        $statements = [
            new OrderingScriptedStatement(fetchColumnQueue: [1, 2, 3, false]),
            new OrderingScriptedStatement(fetchColumnQueue: [$currentOrder]),
        ];
        if ($operation === 'updateTargetOrder') {
            $statements[] = new OrderingScriptedStatement();
        }
        $statements[] = $badWrite;

        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(count($statements)))->method('prepare')->willReturn(...$statements);
        $pdo->expects(self::never())->method('commit');
        if ($callerOwned) {
            $controlStatements = [];
            $pdo->method('inTransaction')->willReturn(true);
            $pdo->expects(self::never())->method('beginTransaction');
            $pdo->expects(self::never())->method('rollBack');
            $pdo->expects(self::exactly(3))->method('exec')->willReturnCallback(static function (string $sql) use (&$controlStatements): int {
                $controlStatements[] = $sql;

                return 0;
            });
        } else {
            $pdo->method('inTransaction')->willReturn(false, false, true);
            $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
            $pdo->expects(self::once())->method('rollBack')->willReturn(true);
            $pdo->expects(self::never())->method('exec');
        }

        try {
            (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig('items'), null, 1, $newOrder);
            self::fail('Expected an ordering execution failure.');
        } catch (OrderingExecutionException $failure) {
            self::assertSame(sprintf('Failed to execute the ordering statement for %s.', $operation), $failure->getMessage());
        }

        if ($callerOwned) {
            $savepoint = $controlStatements[0];
            self::assertMatchesRegularExpression('/^SAVEPOINT maatify_persistence_sp_[0-9a-f]{32}$/', $savepoint);
            self::assertSame(['ROLLBACK TO ' . $savepoint, 'RELEASE ' . $savepoint], array_slice($controlStatements, 1));
        }
    }

    /** @return iterable<string, array{string, bool, int, int}> */
    public static function failedWrites(): iterable
    {
        yield 'owned upward shift' => ['shiftOrdersUp', false, 3, 2];
        yield 'caller-owned downward shift' => ['shiftOrdersDown', true, 1, 2];
        yield 'owned target after shift' => ['updateTargetOrder', false, 3, 2];
        yield 'caller-owned target after shift' => ['updateTargetOrder', true, 3, 2];
    }
}
