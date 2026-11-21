<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Criteria;

use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;

/** Trusted Host registration of statements that preserve the transaction. */
final readonly class ProtectedTransactionCommandCatalog
{
    /** @var array<array-key, ProtectedTransactionCommandPlan> */
    private array $commands;

    /** @param array<array-key, mixed> $commands */
    public function __construct(array $commands)
    {
        $validated = [];
        foreach ($commands as $id => $command) {
            if ($id === '' || !$command instanceof ProtectedTransactionCommandPlan) {
                throw new ProtectedTransactionException('Invalid protected transaction command registration.');
            }
            $validated[$id] = $command;
        }
        $this->commands = $validated;
    }

    /** @internal */
    public function command(string $id): ProtectedTransactionCommandPlan
    {
        return $this->commands[$id] ?? throw new ProtectedTransactionException('Unknown protected transaction command.');
    }
}
