<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * Real database (B-10): package Transaction public API against an actual PDO
 * connection, proving commit, rollback and caller-ownership on the server
 * that the stubbed-PDO unit suite cannot.
 */
final class PdoTransactionRunnerIntegrationTest extends IntegrationTestCase
{
    /** @return iterable<string, array{int|false}> */
    public static function callbackValues(): iterable
    {
        yield 'value' => [42];
        yield 'false' => [false];
    }

    #[DataProvider('callbackValues')]
    public function testOwnedNormalReturnCommitsEvenWhenTheCallbackReturnsFalse(int|false $result): void
    {
        $writer = $this->connect();
        $reader = $this->connect();
        $table = $this->createTable($writer);

        $returned = (new PdoTransactionRunner($writer))->run(static function () use ($writer, $table, $result): int|false {
            $writer->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');

            return $result;
        });

        self::assertSame($result, $returned);
        self::assertFalse($writer->inTransaction());
        self::assertSame([10], $this->values($reader, $table));
    }

    public function testOwnedTransactionFailureRollsBackAndRethrowsExactThrowable(): void
    {
        $pdo = $this->connect();
        $reader = $this->connect();
        $table = $this->createTable($pdo);
        $failure = new RuntimeException('owned transaction failure.');

        $thrown = $this->catchThrowable(fn() => (new PdoTransactionRunner($pdo))->run(
            function () use ($pdo, $table, $failure): never {
                $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');

                throw $failure;
            },
        ));

        self::assertSame($failure, $thrown);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->values($pdo, $table));
        self::assertSame([], $this->values($reader, $table));
    }

    public function testCallerOwnedTransactionParticipatesWithoutChangingOwnership(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);

        $pdo->beginTransaction();
        try {
            $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
            (new PdoTransactionRunner($pdo))->run(static function () use ($pdo, $table): void {
                $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (2, 20)');
            });

            self::assertTrue($pdo->inTransaction());
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame([10, 20], $this->values($pdo, $table));
    }

    public function testCallerOwnedTransactionFailureInsideTheRunnerLeavesRollbackToTheCaller(): void
    {
        $pdo = $this->connect();
        $table = $this->createTable($pdo);
        $failure = new RuntimeException('participating operation failure.');

        $pdo->beginTransaction();
        try {
            $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (1, 10)');
            $thrown = $this->catchThrowable(fn() => (new PdoTransactionRunner($pdo))->run(
                static function () use ($pdo, $table, $failure): never {
                    $pdo->exec('INSERT INTO `' . $table . '` (id, v) VALUES (2, 20)');

                    throw $failure;
                },
            ));

            self::assertSame($failure, $thrown);
            self::assertTrue($pdo->inTransaction(), 'The runner never rolls back a transaction it does not own.');
            self::assertSame([10, 20], $this->values($pdo, $table), 'Nothing of the caller-owned transaction was undone by the runner.');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame([], $this->values($pdo, $table));
    }

    /** @param callable(): mixed $callback */
    private function catchThrowable(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected a Throwable.');
    }
}
