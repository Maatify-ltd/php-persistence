<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

/** Registered command authority for the active callback and its Fiber only. */
interface VerifiedTransactionCommandsInterface
{
    /**
     * @param array<array-key, mixed> $bindings Exact names without colons, with string, int, bool or null values.
     * @return int|list<array<array-key, mixed>> Affected-row count or bounded associative rows.
     */
    public function runRegisteredCommand(string $commandId, array $bindings = []): int|array;
}
