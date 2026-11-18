<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\PdoTransactionRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class TransactionCleanupFailureTest extends TestCase
{
    /** @return array<string, array{?Throwable, PDOException|false|null, bool}> */
    public static function ownedFailures(): array
    {
        return [
            'callback throws' => [new RuntimeException('callback failed'), null, true],
            'commit throws' => [null, new PDOException('commit failed'), true],
            'commit returns false' => [null, false, true],
            'callback throws after transaction ends' => [new RuntimeException('callback failed'), null, false],
        ];
    }

    #[DataProvider('ownedFailures')]
    public function testOwnedFailureSurvivesCleanupProbe(
        ?Throwable $callbackFailure,
        PDOException|false|null $commitFailure,
        bool $cleanupProbeThrows,
    ): void {
        $pdo = $this->createMock(PDO::class);
        $probes = 0;
        $pdo->expects(self::exactly(2))->method('inTransaction')->willReturnCallback(
            static function () use (&$probes, $cleanupProbeThrows): bool {
                if (++$probes === 2 && $cleanupProbeThrows) {
                    throw new PDOException('cleanup state probe failed');
                }

                return false;
            },
        );
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::never())->method('exec');

        if ($callbackFailure !== null) {
            $pdo->expects(self::never())->method('commit');
        } elseif ($commitFailure instanceof PDOException) {
            $pdo->expects(self::once())->method('commit')->willThrowException($commitFailure);
        } else {
            $pdo->expects(self::once())->method('commit')->willReturn(false);
        }

        $thrown = $this->catchThrowable(
            static fn() => (new PdoTransactionRunner($pdo))->run(
                static function () use ($callbackFailure): string {
                    if ($callbackFailure !== null) {
                        throw $callbackFailure;
                    }

                    return 'callback completed';
                },
            ),
        );

        if ($commitFailure === false) {
            self::assertInstanceOf(TransactionExecutionException::class, $thrown, $thrown->getMessage());
            self::assertSame(
                'PDO commit() failed without throwing; the transaction is not reported as committed.',
                $thrown->getMessage(),
            );
        } else {
            self::assertSame($callbackFailure ?? $commitFailure, $thrown, $thrown->getMessage());
        }
    }

    /** @return array<string, array{?Throwable, PDOException|false|null}> */
    public static function savepointFailures(): array
    {
        return [
            'callback throws' => [new RuntimeException('callback failed'), null],
            'release throws' => [null, new PDOException('release failed')],
            'release returns false' => [null, false],
        ];
    }

    #[DataProvider('savepointFailures')]
    public function testCallerOwnedFailureSurvivesCleanupProbe(
        ?Throwable $callbackFailure,
        PDOException|false|null $releaseFailure,
    ): void {
        $pdo = $this->createMock(PDO::class);
        $probes = 0;
        $pdo->expects(self::exactly(3))->method('inTransaction')->willReturnCallback(
            static function () use (&$probes): bool {
                if (++$probes === 3) {
                    throw new PDOException('cleanup state probe failed');
                }

                return true;
            },
        );
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');
        $statements = [];
        $pdo->expects(self::exactly($callbackFailure === null ? 2 : 1))->method('exec')->willReturnCallback(
            static function (string $statement) use (&$statements, $releaseFailure): int|false {
                $statements[] = $statement;
                if (str_starts_with($statement, 'RELEASE SAVEPOINT ')) {
                    if ($releaseFailure instanceof PDOException) {
                        throw $releaseFailure;
                    }

                    return false;
                }

                return 0;
            },
        );

        $thrown = $this->catchThrowable(
            static fn() => (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use ($callbackFailure): string {
                    if ($callbackFailure !== null) {
                        throw $callbackFailure;
                    }

                    return 'callback completed';
                },
            ),
        );

        self::assertMatchesRegularExpression('/\ASAVEPOINT maatify_persistence_sp_[0-9a-f]{32}\z/', $statements[0]);
        if ($callbackFailure === null) {
            self::assertSame('RELEASE ' . $statements[0], $statements[1]);
        }

        if ($releaseFailure === false) {
            self::assertInstanceOf(TransactionExecutionException::class, $thrown, $thrown->getMessage());
            self::assertSame('PDO transaction control statement failed.', $thrown->getMessage());
        } else {
            self::assertSame($callbackFailure ?? $releaseFailure, $thrown, $thrown->getMessage());
        }
    }

    private function catchThrowable(callable $run): Throwable
    {
        try {
            $run();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected the transaction failure to propagate.');
    }
}
