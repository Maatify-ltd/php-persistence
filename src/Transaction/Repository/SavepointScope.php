<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Maatify\PhpPersistence\Transaction\Exception\TransactionExecutionException;
use PDO;
use Throwable;

/**
 * Operation-local savepoint inside a transaction the caller already owns:
 * `SAVEPOINT`, `RELEASE SAVEPOINT` and `ROLLBACK TO SAVEPOINT`, with the
 * best-effort cleanup order and exception precedence that
 * {@see PdoSavepointTransactionRunner} characterizes (DEC-011 item 3).
 *
 * - {@see self::releaseOrUndo()} is the success path: RELEASE, or best-effort
 *   cleanup followed by the RELEASE failure when it fails.
 * - {@see self::cleanupAfterFailure()} checks for an active transaction
 *   before attempting ROLLBACK TO. It attempts RELEASE only after rollback
 *   is acknowledged. The initiating Throwable keeps precedence over
 *   state-check and cleanup failures.
 * - {@see self::undoOrFail()} is for a caller that reports failure without
 *   throwing. Raw Ordering requires acknowledged ROLLBACK TO and RELEASE
 *   before returning its zero-row result. A protected receiver records
 *   cleanup failure for the checkout to settle.
 * - {@see self::begin()} fails before any mutation when the driver reports no
 *   active transaction right after the SAVEPOINT reply: a server-aborted
 *   caller transaction that the caller swallowed shows up here on the
 *   measured engines (legacy `OperationSavepoint`; restored in the shared
 *   helper after PR #13 review #1, finding F13-01).
 *
 * It never begins, commits, fully rolls back or retries the caller-owned
 * transaction, and never retries the savepoint itself.
 *
 * @internal Shared mechanism of the savepoint runner; not part of the public API.
 */
final readonly class SavepointScope
{
    private const SAVEPOINT_PREFIX = 'maatify_persistence_sp_';

    private function __construct(private PDO|ProtectedTransactionConnection $pdo, private string $name) {}

    /**
     * @throws TransactionExecutionException when SAVEPOINT returns false, or when the driver
     *                                        reports no active transaction right after it succeeds
     */
    public static function begin(PDO|ProtectedTransactionConnection $pdo): self
    {
        $scope = self::open($pdo);

        if (!$pdo->inTransaction()) {
            throw new TransactionExecutionException('The caller-owned transaction is no longer active; the operation was not started.');
        }

        return $scope;
    }

    /** Opens the savepoint, leaving full continuity verification to a strict caller. */
    public static function open(PDO|ProtectedTransactionConnection $pdo): self
    {
        $scope = new self($pdo, self::SAVEPOINT_PREFIX . bin2hex(random_bytes(16)));
        $scope->executeControlStatement('SAVEPOINT ' . $scope->name);

        return $scope;
    }

    /** Requires acknowledgement, without falling back to best-effort cleanup. */
    public function releaseStrict(): void
    {
        $this->executeControlStatement('RELEASE SAVEPOINT ' . $this->name);
    }

    /** Requires acknowledgement before a caller can record a local undo. */
    public function rollbackStrict(): void
    {
        $this->executeControlStatement('ROLLBACK TO SAVEPOINT ' . $this->name);
    }

    /**
     * Success path: RELEASE. If it fails, the window is undone best effort and the release failure is thrown.
     *
     * @throws Throwable when RELEASE fails
     */
    public function releaseOrUndo(): void
    {
        try {
            $this->releaseStrict();
        } catch (Throwable $releaseThrowable) {
            $this->cleanupAfterFailure();

            throw $releaseThrowable;
        }
    }

    /**
     * On an active connection, attempts local rollback before release. A
     * failed rollback skips release. A fenced protected receiver declines
     * further SQL, and the caller rethrows the primary throwable.
     */
    public function cleanupAfterFailure(): void
    {
        try {
            if (!$this->pdo->inTransaction()) {
                return;
            }
        } catch (Throwable) {
            // The caller still has the primary failure to rethrow.
            return;
        }

        if (!$this->tryControlStatement('ROLLBACK TO SAVEPOINT ' . $this->name)) {
            return;
        }

        $this->tryControlStatement('RELEASE SAVEPOINT ' . $this->name);
    }

    /**
     * For a non-throwing failed window, ROLLBACK TO must succeed before
     * RELEASE is attempted. Raw PDO requires RELEASE to succeed too. A
     * protected receiver records a RELEASE failure so the checkout fences
     * further work and suppresses a normal result.
     *
     * @throws Throwable when ROLLBACK TO or a raw RELEASE fails
     */
    public function undoOrFail(): void
    {
        $this->rollbackStrict();

        if ($this->pdo instanceof PDO) {
            $this->releaseStrict();

            return;
        }

        $this->tryControlStatement('RELEASE SAVEPOINT ' . $this->name);
    }

    /**
     * Sends control through the protected receiver when delegated, preserving
     * its failure latch. Raw PDO reports a false execution result as
     * TransactionExecutionException. Thrown errors propagate to the caller.
     */
    private function executeControlStatement(string $sql): void
    {
        if ($this->pdo instanceof ProtectedTransactionConnection) {
            $this->pdo->control($sql);
        } elseif ($this->pdo->exec($sql) === false) {
            throw new TransactionExecutionException('PDO transaction control statement failed.');
        }
    }

    /**
     * Turns a failed best-effort control command into false. A protected receiver
     * keeps its recorded failure so the enclosing operation can settle it.
     */
    private function tryControlStatement(string $sql): bool
    {
        try {
            $this->executeControlStatement($sql);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
