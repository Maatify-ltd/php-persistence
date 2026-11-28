<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Ordering;

use LogicException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Minimal scripted PDO for ScopedOrderingManagerSavepointWiringTest: a queue of prepared
 * statements consumed in the exact order moveWithinScope() issues them (scope-lock SELECT,
 * current-order SELECT, shift UPDATE, target UPDATE), and a transaction-active flag that starts
 * at $participating and flips with beginTransaction()/commit()/rollBack() so the double can
 * script both the participating and the owned path. Control statements (SAVEPOINT/RELEASE/
 * ROLLBACK TO) go through exec(). The double records transaction changes
 * and can return a scripted failure or throw at either cleanup boundary.
 */
final class OrderingScriptedPdo extends PDO
{
    /** @var list<string> */
    public array $executedControlStatements = [];

    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;

    /** @var list<PDOStatement> */
    private array $prepareQueue;

    private bool $active;

    /**
     * @param list<PDOStatement> $prepareQueue
     * @param list<int|false|PDOException> $controlResults
     */
    public function __construct(
        array $prepareQueue,
        bool $participating = true,
        private bool|PDOException $rollbackResult = true,
        private array $controlResults = [],
    ) {
        $this->prepareQueue = $prepareQueue;
        $this->active = $participating;
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }

    public function beginTransaction(): bool
    {
        $this->beginCalls++;
        $this->active = true;

        return true;
    }

    public function commit(): bool
    {
        $this->commitCalls++;
        $this->active = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbackCalls++;
        if ($this->rollbackResult instanceof PDOException) {
            throw $this->rollbackResult;
        }
        if ($this->rollbackResult) {
            $this->active = false;
        }

        return $this->rollbackResult;
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement
    {
        unset($query, $options);

        if ($this->prepareQueue === []) {
            throw new LogicException('Ordering scripted PDO prepare queue exhausted.');
        }

        return array_shift($this->prepareQueue);
    }

    public function exec(string $statement): int|false
    {
        $this->executedControlStatements[] = $statement;
        $result = array_shift($this->controlResults) ?? 0;
        if ($result instanceof PDOException) {
            throw $result;
        }

        return $result;
    }
}
