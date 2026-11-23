<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Tests\Support\Pagination\PaginationFixture;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Pdo\Mysql;
use RuntimeException;

final class PaginationOrderingSavepointTest extends PaginationIntegrationTestCase
{
    public function testHostCanAllocateAfterUnbufferedMapperFailureAndSavepointUndo(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $observer = $this->connect();
        $fixture = new PaginationFixture($pdo, $table);
        $time = '2026-01-01 00:00:00.000000';
        $anchor = $fixture->insertItem(1, 'scope', 'Anchor', 1, true, null, $time);
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);
        $session = $this->scalar($pdo, 'SELECT CONNECTION_ID()');
        $ordering = new ScopedOrderingManager();
        $config = new ScopedOrderingConfig(table: $table, scopeColumn: 'tenant_id', orderColumn: 'score');
        $failure = new RuntimeException('Mapper failed before exhausting the result.');

        $pdo->beginTransaction();
        try {
            // Every writer in this fixture would lock the same existing scope anchor.
            self::assertSame((string) $anchor, $this->scalar($pdo, "SELECT id FROM `$table` WHERE id = $anchor FOR UPDATE"));
            $fixture->insertItem(1, 'scope', 'Earlier Host write', 2, true, null, $time);

            try {
                (new PdoSavepointTransactionRunner($pdo))->run(function () use ($pdo, $table, $fixture, $time, $failure): void {
                    $fixture->insertItem(1, 'scope', 'Failed operation write', 3, true, null, $time);
                    (new PdoPaginator())->paginate($pdo, $this->descriptor($table), new PageRequest(), $this->config(), static fn(array $row): never => throw $failure);
                });
                self::fail('Expected the mapper failure.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }

            self::assertTrue($pdo->inTransaction());
            $next = $ordering->getNextPositionForUpdate($pdo, $config, scopeValue: 1);
            self::assertSame(3, $next, 'The savepoint undo must keep the earlier Host write and remove the failed operation write.');
            $fixture->insertItem(1, 'scope', 'Host continuation', $next, true, null, $time);
            self::assertSame($session, $this->scalar($pdo, 'SELECT CONNECTION_ID()'));
            self::assertSame('3', $this->scalar($pdo, "SELECT COUNT(*) FROM `$table`"));
            self::assertSame('1', $this->scalar($observer, "SELECT COUNT(*) FROM `$table`"), 'Host writes must remain uncommitted.');
            self::assertTrue($pdo->rollBack());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame('1', $this->scalar($observer, "SELECT COUNT(*) FROM `$table`"), 'Only the Host decides to undo the outer writes.');
    }
}
