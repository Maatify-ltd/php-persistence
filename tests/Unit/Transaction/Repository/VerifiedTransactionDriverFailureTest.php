<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionCommandExecutor;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionDriver;
use Maatify\PhpPersistence\Transaction\Repository\SavepointScope;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerifiedTransactionDriverFailureTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function falseOrThrowable(): iterable
    {
        yield 'false' => [false];
        yield 'throw' => [true];
    }

    #[DataProvider('falseOrThrowable')]
    public function testCloseFailureLatchesAndDoesNotReleaseACommandResult(bool $throws): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $pdo->expects(self::never())->method('exec');
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->expects(self::once())->method('rowCount')->willReturn(1);
        $primary = new PDOException('close fault');
        $close = $statement->expects(self::once())->method('closeCursor');
        $throws ? $close->willThrowException($primary) : $close->willReturn(false);

        $receiver = new ProtectedTransactionConnection($pdo);
        try {
            ProtectedTransactionCommandExecutor::run(
                $receiver,
                new ProtectedTransactionCommandPlan('UPDATE ledger SET amount = 5', []),
                [],
                releaseBeforeReturn: true,
            );
            self::fail('Unconfirmed cursor cleanup must suppress the result.');
        } catch (ProtectedTransactionException|PDOException $caught) {
            self::assertSame($caught, $receiver->failure());
            self::assertTrue($receiver->cursorFailure());
            if ($throws) {
                self::assertSame($primary, $caught);
            } else {
                self::assertInstanceOf(ProtectedTransactionException::class, $caught);
            }
            try {
                $receiver->control('ROLLBACK');
                self::fail('A latched receiver must reject SQL.');
            } catch (ProtectedTransactionException|PDOException $blocked) {
                self::assertSame($caught, $blocked);
            }
        } finally {
            $receiver->seal();
        }
    }

    /** @return iterable<string, array{int, bool}> */
    public static function savepointFaults(): iterable
    {
        foreach (['savepoint' => 1, 'rollback to' => 2, 'release' => 3] as $phase => $step) {
            foreach (self::falseOrThrowable() as $fault => [$throws]) {
                yield "$phase $fault" => [$step, $throws];
            }
        }
    }

    #[DataProvider('savepointFaults')]
    public function testUnconfirmedStrictControlDoesNotSendAnotherControl(int $step, bool $throws): void
    {
        $pdo = $this->createMock(PDO::class);
        $commands = [];
        $fault = new PDOException('control fault');
        $pdo->expects(self::exactly($step))->method('exec')->willReturnCallback(
            static function (string $sql) use (&$commands, $step, $throws, $fault): int|false {
                $commands[] = $sql;
                if (count($commands) !== $step) {
                    return 0;
                }
                if ($throws) {
                    throw $fault;
                }

                return false;
            },
        );

        $receiver = new ProtectedTransactionConnection($pdo);
        try {
            $scope = SavepointScope::open($receiver);
            $scope->rollbackStrict();
            $scope->releaseStrict();
            self::fail('Unconfirmed control must fail.');
        } catch (ProtectedTransactionException|PDOException $caught) {
            self::assertSame($caught, $receiver->failure());
            self::assertSame($throws ? $fault : ProtectedTransactionException::class, $throws ? $caught : $caught::class);
        } finally {
            $receiver->seal();
        }
        self::assertMatchesRegularExpression('/\\ASAVEPOINT maatify_persistence_sp_[0-9a-f]{32}\\z/', $commands[0]);
        if ($step >= 2) {
            self::assertSame('ROLLBACK TO ' . $commands[0], $commands[1]);
        }
        if ($step === 3) {
            self::assertSame('RELEASE ' . $commands[0], $commands[2]);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function transactionFaults(): iterable
    {
        foreach (['START TRANSACTION READ WRITE', 'COMMIT AND NO CHAIN NO RELEASE', 'ROLLBACK AND NO CHAIN NO RELEASE'] as $sql) {
            foreach (self::falseOrThrowable() as $fault => [$throws]) {
                yield "$sql $fault" => [$sql, $throws];
            }
        }
    }

    #[DataProvider('transactionFaults')]
    public function testOwnedStartAndExplicitEndsRequireAcknowledgement(string $sql, bool $throws): void
    {
        $pdo = $this->createMock(PDO::class);
        $expect = $pdo->expects(self::once())->method('exec')->with($sql);
        $fault = new PDOException('control fault');
        $throws ? $expect->willThrowException($fault) : $expect->willReturn(false);
        try {
            if ($sql === 'START TRANSACTION READ WRITE') {
                ProtectedTransactionDriver::start($pdo);
            } else {
                ProtectedTransactionDriver::end($pdo, $sql === 'COMMIT AND NO CHAIN NO RELEASE');
            }
            self::fail('Unconfirmed control must fail.');
        } catch (ProtectedTransactionException|PDOException $caught) {
            self::assertSame($throws ? $fault : ProtectedTransactionException::class, $throws ? $caught : $caught::class);
        }
    }
}
