<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Support;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Real database connection that can make a matching control statement fail
 * (exec() returns false or throws, the statement is NOT executed), a
 * matching prepare() throw, or the next rollBack() fail before dispatch.
 * These faults leave the surrounding work on the real server. Originally captured from
 * legacy tests/Support/MySql/FaultInjectingPdo.php (1f6a7aa, provenance gate
 * recorded in the WU-8 pull request).
 */
final class FaultInjectingPdo extends PDO
{
    /** @var list<string> */
    public array $executed = [];

    /** @var list<array{regex: string, mode: PDOException|'false'|'throw'}> */
    private array $execFaults = [];

    /** @var list<string> */
    private array $prepareFaults = [];

    private PDOException|false|null $rollbackFault = null;

    /** @param PDOException|'false'|'throw' $mode */
    public function failExec(string $regex, PDOException|string $mode): void
    {
        $this->execFaults[] = ['regex' => $regex, 'mode' => $mode];
    }

    public function failPrepare(string $regex): void
    {
        $this->prepareFaults[] = $regex;
    }

    public function failNextRollback(PDOException|false $fault): void
    {
        $this->rollbackFault = $fault;
    }

    public function rollBack(): bool
    {
        $fault = $this->rollbackFault;
        $this->rollbackFault = null;
        if ($fault instanceof PDOException) {
            throw $fault;
        }

        return $fault === false ? false : parent::rollBack();
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        foreach ($this->execFaults as $fault) {
            if (preg_match($fault['regex'], $statement) === 1) {
                if ($fault['mode'] === 'false') {
                    return false;
                }

                if ($fault['mode'] instanceof PDOException) {
                    throw $fault['mode'];
                }

                throw new PDOException('Injected control statement failure.');
            }
        }

        return parent::exec($statement);
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        foreach ($this->prepareFaults as $regex) {
            if (preg_match($regex, $query) === 1) {
                throw new RuntimeException('Injected prepare failure.');
            }
        }

        return parent::prepare($query, $options);
    }

    /** @return list<string> control statements issued so far, in order */
    public function savepointStatements(): array
    {
        return array_values(array_filter(
            $this->executed,
            static fn(string $sql): bool => str_contains($sql, 'SAVEPOINT'),
        ));
    }
}
