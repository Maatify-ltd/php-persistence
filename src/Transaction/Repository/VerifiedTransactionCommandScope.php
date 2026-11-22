<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

use Closure;
use Fiber;
use WeakMap;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;

/** @internal Command grant for one invocation under a checkout reservation. */
final class VerifiedTransactionCommandScope implements VerifiedTransactionCommandsInterface
{
    /** @var WeakMap<object, true>|null */
    private ?WeakMap $owner = null;

    /** @param (Closure(string, array<array-key, mixed>): (int|list<array<array-key, mixed>>))|null $command */
    public function __construct(private ?Closure $command)
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            $this->owner = new WeakMap();
            $this->owner[$fiber] = true;
        }
    }

    public function runRegisteredCommand(string $commandId, array $bindings = []): int|array
    {
        $fiber = Fiber::getCurrent();
        if ($this->command === null || ($this->owner === null ? $fiber !== null : $fiber === null || !isset($this->owner[$fiber]))) {
            throw new ProtectedTransactionException('The verified transaction command scope is unavailable.');
        }

        return ($this->command)($commandId, $bindings);
    }

    public function close(): void
    {
        $this->command = null;
        $this->owner = null;
    }

    private function __clone() {}

    /** @return array<array-key, mixed> */
    public function __serialize(): array
    {
        throw new ProtectedTransactionException('A verified transaction command scope cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new ProtectedTransactionException('A verified transaction command scope cannot be restored.');
    }
}
