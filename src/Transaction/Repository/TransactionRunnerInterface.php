<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

/**
 * Executes a callback with the transaction ownership rules of the implementation.
 */
interface TransactionRunnerInterface
{
    /**
     * Returns the callback result after successful execution. When a callback
     * failure escapes, implementations propagate the original throwable.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback
     *
     * @return TResult
     */
    public function run(callable $callback): mixed;
}
