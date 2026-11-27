<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Transaction\Repository;

use Maatify\PhpPersistence\Tests\Support\Transaction\SavepointTransactionPdo;
use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\PhpPersistence\Transaction\Repository\SavepointScope;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Isolated contract tests for the shared @internal helper (DEC-011 item 2: one
 * savepoint helper owned by Transaction, consumed by Ordering). Ordering's
 * production code is out of this work unit's scope; these tests prove the
 * helper's low-level shape (begin/releaseOrUndo/cleanupAfterFailure/undoOrFail)
 * by itself, the same way the legacy repository's
 * tests/Unit/Pdo/Ordering/OperationSavepointTest.php (commit
 * 1f6a7aafe601090b7e55f60d83794364afdefe67; provenance gate recorded in the
 * pull request) characterized its OperationSavepoint helper against the
 * savepoint runner, without wiring a consumer.
 *
 * testSameStatementSequenceAndExceptionPrecedenceAsTheSavepointRunner is the
 * structural analogue of that legacy test: here it compares
 * PdoSavepointTransactionRunner (which now calls this same class) against
 * direct low-level use of SavepointScope, proving the two call shapes the
 * helper supports agree.
 *
 * testBeginFailsBeforeAnyMutationWhenTheDriverReportsNoActiveTransaction and
 * testBeginFailsWhenTheSavepointReplyRevealsAnEndedTransaction below port the
 * legacy OperationSavepoint::begin() guard verbatim: it fails before any
 * mutation when the driver reports no active transaction right after a
 * successful SAVEPOINT (a server-aborted caller transaction the caller
 * swallowed). The guard was first left out of this shared helper on the
 * theory that Transaction's own characterized runner behavior (B-11) never
 * needs it; PR #13 review #1 (finding F13-01) held that DEC-011's "one
 * savepoint helper... consumed by Ordering" requires carrying it in the
 * shared helper now, since dropping it changed OperationSavepoint's
 * characterized contract. Restoring it does not change any Transaction Gen2
 * runner test in this file or in PdoSavepointTransactionRunnerTest: none of
 * those scenarios reports the transaction as inactive immediately after a
 * successful SAVEPOINT.
 */
final class SavepointScopeTest extends TestCase
{
    public function testSavepointNameFormat(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);

        SavepointScope::begin($pdo)->releaseOrUndo();

        self::assertCount(2, $pdo->executedStatements);
        self::assertMatchesRegularExpression('/^SAVEPOINT maatify_persistence_sp_[0-9a-f]{32}$/', $pdo->executedStatements[0]);
        self::assertSame(
            'RELEASE SAVEPOINT ' . substr($pdo->executedStatements[0], strlen('SAVEPOINT ')),
            $pdo->executedStatements[1],
        );
    }

    /**
     * @return iterable<string, array{list<int|false|Throwable>, 'success'|'failure'}>
     */
    public static function scenarios(): iterable
    {
        $failure = new PDOException('control failure');

        yield 'success' => [[0, 0], 'success'];
        yield 'callback failure, cleanup succeeds' => [[0, 0, 0], 'failure'];
        yield 'callback failure, ROLLBACK TO false' => [[0, false], 'failure'];
        yield 'callback failure, ROLLBACK TO throws' => [[0, $failure], 'failure'];
        yield 'callback failure, RELEASE after ROLLBACK TO false' => [[0, 0, false], 'failure'];
        yield 'RELEASE false' => [[0, false, 0, 0], 'success'];
        yield 'RELEASE throws' => [[0, $failure, 0, 0], 'success'];
        yield 'RELEASE false, cleanup fails' => [[0, false, false], 'success'];
        yield 'SAVEPOINT false' => [[false], 'success'];
        yield 'SAVEPOINT throws' => [[$failure], 'failure'];
    }

    /**
     * @param list<int|false|Throwable> $execResults
     * @param 'success'|'failure'       $callbackOutcome
     */
    #[DataProvider('scenarios')]
    public function testSameStatementSequenceAndExceptionPrecedenceAsTheSavepointRunner(array $execResults, string $callbackOutcome): void
    {
        $callbackFailure = new RuntimeException('callback failure');

        $runnerPdo = new SavepointTransactionPdo(transactionActive: true, execResults: $execResults);
        $runnerThrown = $this->catchThrowable(
            static fn() => (new PdoSavepointTransactionRunner($runnerPdo))->run(
                static function () use ($callbackOutcome, $callbackFailure): bool {
                    if ($callbackOutcome === 'failure') {
                        throw $callbackFailure;
                    }

                    return true;
                },
            ),
        );

        $helperPdo = new SavepointTransactionPdo(transactionActive: true, execResults: $execResults);
        $helperThrown = $this->catchThrowable(
            static function () use ($helperPdo, $callbackOutcome, $callbackFailure): void {
                $scope = SavepointScope::begin($helperPdo);

                if ($callbackOutcome === 'failure') {
                    $scope->cleanupAfterFailure();

                    throw $callbackFailure;
                }

                $scope->releaseOrUndo();
            },
        );

        self::assertSame(
            $this->normalize($runnerPdo->executedStatements),
            $this->normalize($helperPdo->executedStatements),
        );
        self::assertSame($runnerThrown === null, $helperThrown === null);

        if ($runnerThrown !== null && $helperThrown !== null) {
            self::assertSame($runnerThrown::class, $helperThrown::class);
            self::assertSame($runnerThrown->getMessage(), $helperThrown->getMessage());
        }

        self::assertSame(0, $helperPdo->beginTransactionCalls + $helperPdo->commitCalls + $helperPdo->rollBackCalls);
    }

    public function testUndoOrFailRollsBackAndReleasesInOrder(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);

        SavepointScope::begin($pdo)->undoOrFail();

        self::assertSame(['SAVEPOINT SP', 'ROLLBACK TO SAVEPOINT SP', 'RELEASE SAVEPOINT SP'], $this->normalize($pdo->executedStatements));
    }

    public function testUndoOrFailPropagatesAFalseRollbackToInsteadOfSwallowingIt(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true, execResults: [0, false]);
        $scope = SavepointScope::begin($pdo);

        $this->expectException(TransactionExecutionException::class);

        $scope->undoOrFail();
    }

    public function testUndoOrFailPropagatesAThrowingRollbackTo(): void
    {
        $failure = new PDOException('rollback to failed');
        $pdo = new SavepointTransactionPdo(transactionActive: true, execResults: [0, $failure]);
        $scope = SavepointScope::begin($pdo);

        self::assertSame($failure, $this->catchThrowable(static fn() => $scope->undoOrFail()));
    }

    public function testUndoOrFailPropagatesAFalseRawReleaseAfterASuccessfulRollbackTo(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true, execResults: [0, 0, false]);

        $this->expectException(TransactionExecutionException::class);
        try {
            SavepointScope::begin($pdo)->undoOrFail();
        } finally {
            self::assertSame(['SAVEPOINT SP', 'ROLLBACK TO SAVEPOINT SP', 'RELEASE SAVEPOINT SP'], $this->normalize($pdo->executedStatements));
            self::assertTrue($pdo->inTransaction());
        }
    }

    public function testBeginFailsBeforeAnyMutationWhenTheDriverReportsNoActiveTransaction(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: false);

        $this->expectException(TransactionExecutionException::class);

        try {
            SavepointScope::begin($pdo);
        } finally {
            self::assertSame(['SAVEPOINT SP'], $this->normalize($pdo->executedStatements));
            self::assertSame(0, $pdo->beginTransactionCalls + $pdo->commitCalls + $pdo->rollBackCalls);
        }
    }

    public function testBeginFailsWhenTheSavepointReplyRevealsAnEndedTransaction(): void
    {
        $pdo = new class (true) extends PDO {
            /** @var list<string> */
            public array $executed = [];

            public function __construct(private bool $active) {}

            public function inTransaction(): bool
            {
                return $this->active;
            }

            public function exec(string $statement): int
            {
                $this->executed[] = $statement;
                $this->active = false;

                return 0;
            }
        };

        try {
            SavepointScope::begin($pdo);
            self::fail('Expected the dead-transaction guard.');
        } catch (TransactionExecutionException) {
        }

        self::assertCount(1, $pdo->executed);
    }

    public function testHelperIsAnInternalFinalReadonlyClass(): void
    {
        $class = new ReflectionClass(SavepointScope::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function normalize(array $statements): array
    {
        return array_map(
            static fn(string $sql): string => (string) preg_replace('/maatify_persistence_sp_[0-9a-f]{32}/', 'SP', $sql),
            $statements,
        );
    }

    private function catchThrowable(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }
}
