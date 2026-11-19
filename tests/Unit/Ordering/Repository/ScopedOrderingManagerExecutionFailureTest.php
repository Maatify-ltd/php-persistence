<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Ordering\Repository;

use Closure;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedStatement;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopedOrderingManagerExecutionFailureTest extends TestCase
{
    /** @param non-empty-list<int|false> $tails */
    #[DataProvider('lockingTails')]
    public function testLockingAllocatorRereadsUntilTwoConsecutiveTailsAgree(array $tails, int $position): void
    {
        $iterations = count($tails);
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::exactly($iterations))->method('execute')->willReturn(true);
        $statement->expects(self::exactly($iterations))->method('fetchColumn')->willReturn(...$tails);
        $statement->expects(self::exactly($iterations))->method('closeCursor')->willReturn(true);
        $statement->method('errorCode')->willReturn('00000');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        self::assertSame($position, (new ScopedOrderingManager())->getNextPositionForUpdate($pdo, new ScopedOrderingConfig('items')));
    }

    /** @return iterable<string, array{non-empty-list<int|false>, int}> */
    public static function lockingTails(): iterable
    {
        yield 'stable tail' => [[2, 2], 3];
        yield 'empty scope' => [[false, false], 1];
        yield 'two changes' => [[2, 3, 4, 4], 5];
        yield 'tail jumps' => [[2, 6, 9, 9], 10];
    }

    public function testNextPositionsReturnTheLargestRepresentableValue(): void
    {
        $manager = new ScopedOrderingManager();
        $config = new ScopedOrderingConfig('items');
        $plain = new OrderingScriptedPdo([new OrderingScriptedStatement(fetchColumnQueue: [PHP_INT_MAX - 1])]);
        $locking = new OrderingScriptedPdo([new OrderingScriptedStatement(fetchColumnQueue: [PHP_INT_MAX - 1, PHP_INT_MAX - 1])]);

        self::assertSame(PHP_INT_MAX, $manager->getNextPosition($plain, $config));
        self::assertSame(PHP_INT_MAX, $manager->getNextPositionForUpdate($locking, $config));
    }

    #[DataProvider('representableDecimalOrders')]
    public function testNextPositionKeepsRepresentablePdoDecimalStrings(string $order, int $next): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->expects(self::once())->method('fetchColumn')->willReturn($order);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);

        self::assertSame($next, (new ScopedOrderingManager())->getNextPosition($pdo, new ScopedOrderingConfig('items')));
    }

    /** @return iterable<string, array{string, int}> */
    public static function representableDecimalOrders(): iterable
    {
        yield 'zero-padded positive' => ['+00' . (PHP_INT_MAX - 1), PHP_INT_MAX];
        yield 'minimum signed integer' => [(string) PHP_INT_MIN, PHP_INT_MIN + 1];
    }

    #[DataProvider('nextPositionMethods')]
    public function testNextPositionRejectsOverflow(bool $locking): void
    {
        $manager = new ScopedOrderingManager();
        $config = new ScopedOrderingConfig('items');
        $pdo = new OrderingScriptedPdo([new OrderingScriptedStatement(fetchColumnQueue: [PHP_INT_MAX, PHP_INT_MAX])]);

        $this->expectException(OrderingExecutionException::class);
        $this->expectExceptionMessage('The next ordering position is outside the PHP integer range.');

        if ($locking) {
            $manager->getNextPositionForUpdate($pdo, $config);
        } else {
            $manager->getNextPosition($pdo, $config);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function nextPositionMethods(): iterable
    {
        yield 'plain' => [false];
        yield 'locking' => [true];
    }

    public function testMoveRejectsUnrepresentableStoredOrderBeforeMutation(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->expects(self::once())->method('fetchColumn')->with(1)->willReturn(PHP_INT_MAX . '0');
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false, false, true);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');

        $this->expectException(OrderingExecutionException::class);
        $this->expectExceptionMessage('The ordering result is outside the PHP integer range.');

        (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig('items'), null, 1, PHP_INT_MAX);
    }

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

    public function testFailureAfterFirstTailReadPreservesFetchExceptionAndCallerOwnership(): void
    {
        $failure = new PDOException('Second locking read failed.');
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::exactly(2))->method('execute')->willReturn(true);
        $fetches = 0;
        $statement->expects(self::exactly(2))->method('fetchColumn')->willReturnCallback(
            static function () use (&$fetches, $failure): int {
                if (++$fetches === 2) {
                    throw $failure;
                }

                return 2;
            },
        );
        $statement->expects(self::exactly(2))->method('closeCursor')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        try {
            (new ScopedOrderingManager())->getNextPositionForUpdate($pdo, new ScopedOrderingConfig('items'));
            self::fail('Expected the second locking read to throw.');
        } catch (PDOException $caught) {
            self::assertSame($failure, $caught);
        }
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
