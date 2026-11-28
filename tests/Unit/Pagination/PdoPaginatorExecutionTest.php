<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Pagination;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdoStatement;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoPaginatorExecutionTest extends TestCase
{
    private function config(bool $dup = false): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'id', 'created_at' => $dup ? 'id' : 'created_at']), 'created_at', SortDirectionEnum::Desc, 'id', SortDirectionEnum::Asc);
    }

    public function testOrderSeparateMapsTypedBindingsAndFinalSql(): void
    {
        $total = ScriptedPdoStatement::count(10);
        $filtered = ScriptedPdoStatement::count(5);
        $data = ScriptedPdoStatement::data([['id' => 1]]);
        $pdo = new ScriptedPdo([$total, $filtered, $data]);
        $q = new PdoPaginationQueryDescriptor('TOTAL', ['total_tenant' => 1], 'FILTERED', ['filtered_category' => 'books'], 'DATA', ['data_active' => true, 'none' => null]);
        $r = (new PdoPaginator())->paginate($pdo, $q, new PageRequest(2, 2, 'created_at', 'desc'), $this->config(), fn(array $row): array => $row);
        self::assertSame([
            'TOTAL',
            'FILTERED',
            'DATA
ORDER BY `created_at` DESC, `id` ASC
LIMIT :__pagination_limit
OFFSET :__pagination_offset',
        ], $pdo->preparedSql);
        self::assertSame(':total_tenant', $total->bindCalls[0]['parameter']);
        self::assertSame(PDO::PARAM_INT, $total->bindCalls[0]['type']);
        self::assertSame(':filtered_category', $filtered->bindCalls[0]['parameter']);
        self::assertSame(PDO::PARAM_STR, $filtered->bindCalls[0]['type']);
        self::assertSame([':data_active', ':none', ':__pagination_limit', ':__pagination_offset'], array_column($data->bindCalls, 'parameter'));
        self::assertSame([PDO::PARAM_BOOL, PDO::PARAM_NULL, PDO::PARAM_INT, PDO::PARAM_INT], array_column($data->bindCalls, 'type'));
        self::assertSame(2, $r->page);
    }

    public function testDuplicateTieBreakerZeroOverflowAndMapper(): void
    {
        $data = ScriptedPdoStatement::data([]);
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(10), ScriptedPdoStatement::count(5), $data]);
        $r = (new PdoPaginator())->paginate($pdo, new PdoPaginationQueryDescriptor('T', [], 'F', [], 'D', []), new PageRequest(99, 2), $this->config(true), fn(array $row): object => (object) $row);
        self::assertSame('D
ORDER BY `id` DESC
LIMIT :__pagination_limit
OFFSET :__pagination_offset', $pdo->preparedSql[2]);
        self::assertSame(1, $r->page);
        self::assertSame(1, $data->executeCalls);
    }

    public function testZeroFilteredSkipsDataAndMapperAndTransactions(): void
    {
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(3), ScriptedPdoStatement::count(0)]);
        $called = false;
        $r = (new PdoPaginator())->paginate($pdo, new PdoPaginationQueryDescriptor('T', [], 'F', [], 'D', []), new PageRequest(9, 20), $this->config(), function (array $row) use (&$called): array {
            $called = true;

            return $row;
        });
        self::assertSame(1, $r->page);
        self::assertSame(0, $r->totalPages);
        self::assertFalse($called);
        self::assertCount(2, $pdo->preparedSql);
        self::assertSame(0, $pdo->beginTransactionCalls + $pdo->commitCalls + $pdo->rollBackCalls);
        self::assertSame([], $pdo->setAttributeCalls);
    }
}
