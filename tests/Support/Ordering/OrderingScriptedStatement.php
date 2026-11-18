<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Ordering;

use LogicException;
use PDOStatement;

/**
 * Minimal scripted PDOStatement for ScopedOrderingManagerSavepointWiringTest: a queue of
 * fetchColumn() return values (consumed one call at a time, `false` ends a locking-read loop)
 * and a fixed rowCount(). Its statements report a successful SQLSTATE.
 */
final class OrderingScriptedStatement extends PDOStatement
{
    /** @var list<int|false> */
    private array $fetchColumnQueue;

    /** @var list<array<string, int|string|null>> */
    public array $executedWith = [];

    /**
     * @param list<int|false> $fetchColumnQueue
     */
    public function __construct(private int $rowCount = 0, array $fetchColumnQueue = [])
    {
        $this->fetchColumnQueue = $fetchColumnQueue;
    }

    /** @param array<string, int|string|null>|null $params */
    public function execute(?array $params = null): bool
    {
        $this->executedWith[] = $params ?? [];

        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        unset($column);

        if ($this->fetchColumnQueue === []) {
            throw new LogicException('Ordering scripted statement fetchColumn queue exhausted.');
        }

        return array_shift($this->fetchColumnQueue);
    }

    public function errorCode(): string
    {
        return '00000';
    }

    public function closeCursor(): bool
    {
        return true;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }
}
