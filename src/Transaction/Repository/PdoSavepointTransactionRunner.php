<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\InvalidTransactionConfigurationException;
use PDO;
use Throwable;

/**
 * Runs a callback with an operation-local savepoint inside an existing PDO
 * transaction, and owns the transaction (with optional deadlock replay) when
 * no transaction is active.
 *
 * - No active transaction: the same owned-transaction behavior as
 *   PdoTransactionRunner, including the optional replay of the whole owned
 *   transaction after a MySQL deadlock (SQLSTATE 40001, driver code 1213), at
 *   most $maxDeadlockRetries times, when the end of the transaction is
 *   attributed to that deadlock, and the same fail-closed handling of a BEGIN
 *   or COMMIT that returns false (TransactionExecutionException). No savepoint
 *   SQL is issued on this path.
 * - Active caller-owned transaction: creates an operation-local savepoint
 *   (maatify_persistence_sp_<32 lowercase hex>) through {@see SavepointScope},
 *   releases it on success and rolls back to it (best effort) on failure. It
 *   never commits, fully rolls back or retries the caller-owned transaction or
 *   the savepoint, even on a deadlock; the callback Throwable (or RELEASE
 *   failure) always wins over cleanup failures.
 *
 * Replay re-executes the WHOLE callback of an owned transaction. Its effects
 * outside this PDO connection are not rolled back and would be repeated; keep
 * them outside the callback or make them idempotent. The callback must not
 * begin, commit or roll back the runner-owned transaction itself, or commit it
 * implicitly: keeping it intact is a mandatory precondition of replay.
 */
final readonly class PdoSavepointTransactionRunner implements SavepointTransactionRunnerInterface
{
    private OwnedTransactionExecutor $ownedTransaction;

    /**
     * @param int $maxDeadlockRetries 0 (default: no replay) to 3 replays of an owned transaction after a deadlock
     *
     * @throws InvalidTransactionConfigurationException when $maxDeadlockRetries is outside 0..3
     */
    public function __construct(private PDO $pdo, int $maxDeadlockRetries = 0)
    {
        $this->ownedTransaction = new OwnedTransactionExecutor($pdo, $maxDeadlockRetries);
    }

    /**
     * Returns the callback result after releasing an operation-local savepoint
     * in an existing caller transaction. A callback or RELEASE failure
     * keeps precedence after best-effort local cleanup. If PDO has no active
     * transaction, owns it instead and applies the configured deadlock replay
     * policy. A false owned BEGIN prevents the callback from running. A false
     * owned COMMIT suppresses the result after best-effort rollback.
     *
     * A false result from owned BEGIN, owned COMMIT, SAVEPOINT or RELEASE
     * raises TransactionExecutionException. The same exception is raised if
     * the caller's transaction is no longer active after SAVEPOINT. Any
     * callback or driver Throwable that escapes this call retains its identity.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback
     *
     * @return TResult
     */
    public function run(callable $callback): mixed
    {
        if (!$this->pdo->inTransaction()) {
            return $this->ownedTransaction->run($callback);
        }

        $scope = SavepointScope::begin($this->pdo);

        try {
            $result = $callback();
        } catch (Throwable $callbackThrowable) {
            $scope->cleanupAfterFailure();

            throw $callbackThrowable;
        }

        $scope->releaseOrUndo();

        return $result;
    }
}
