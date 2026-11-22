<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

/**
 * Authority to run registered commands within one live operation callback.
 * Use it only in the invoking Fiber or root context before the callback exits.
 */
interface VerifiedTransactionCommandsInterface
{
    /**
     * Runs a command selected by its catalog ID. Omit bindings for a plan
     * without placeholders. A failed cursor release suppresses the result.
     *
     * @param array<array-key, mixed> $bindings Exact names without colons, with string, int, bool or null values.
     * @return int|list<array<array-key, mixed>> Affected-row count or bounded associative rows.
     * @throws \Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException The grant, command, bindings or result are rejected.
     */
    public function runRegisteredCommand(string $commandId, array $bindings = []): int|array;
}
