<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Ordering;

use Maatify\PhpPersistence\Ordering\Repository\UniqueOrderingSql;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class UniqueOrderingSqlFailureTest extends TestCase
{
    public function testFirstThrowableSurvivesCursorFailure(): void
    {
        $driverFailure = new PDOException('failure in execute');
        $cursorFailure = new PDOException('failure in closeCursor');
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willThrowException($driverFailure);
        $statement->expects(self::once())->method('closeCursor')->willThrowException($cursorFailure);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $receiver = new ProtectedTransactionConnection($pdo);
        $sql = new UniqueOrderingSql($receiver);

        try {
            $sql->rows('SELECT 1');
            self::fail('The statement exception was lost.');
        } catch (PDOException $observed) {
            self::assertSame($driverFailure, $observed);
        }
        self::assertSame($driverFailure, $receiver->failure());
        $receiver->seal();
    }

    public function testCursorFailureSuppressesAnOtherwiseSuccessfulAffectedRowResult(): void
    {
        $cursorFailure = new PDOException('failure in closeCursor');
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->willReturn(true);
        $statement->expects(self::once())->method('rowCount')->willReturn(1);
        $statement->expects(self::once())->method('closeCursor')->willThrowException($cursorFailure);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $receiver = new ProtectedTransactionConnection($pdo);

        try {
            (new UniqueOrderingSql($receiver))->update('UPDATE b1_fixture SET pos = 2', []);
            self::fail('B1 returned an affected-row result with an unconfirmed cursor close.');
        } catch (PDOException $observed) {
            self::assertSame($cursorFailure, $observed);
        }
        self::assertSame($cursorFailure, $receiver->failure());
        self::assertTrue($receiver->cursorFailure());
        $receiver->seal();
    }
}
