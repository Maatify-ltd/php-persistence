<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Closure;
use Maatify\PhpPersistence\Pagination\Criteria\KeysetQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Exception\InvalidKeysetCursorException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoKeysetPaginator;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;

final class PdoKeysetPaginatorIntegrationTest extends PaginationIntegrationTestCase
{
    private const SCOPE = 'fixture:east:tenant-1';

    #[DataProvider('prepareModes')]
    public function testRealPagesUseStrictOrderGroupedPredicatesAndNoOffset(bool $emulate): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);
        self::insertRows($pdo, $table);
        $query = self::query($table);
        $pager = self::pager();
        $bindings = ['tenant' => 1, 'active' => true, 'pin' => 'pinned', 'absent' => null];

        $mapping = static function (array $row) use ($pdo): int {
            self::assertArrayNotHasKey('__ks_id', $row);
            $probe = $pdo->query('SELECT 1');
            self::assertInstanceOf(PDOStatement::class, $probe);
            self::assertTrue($probe->closeCursor());

            return self::mapId($row);
        };
        $mapper = static fn(array $row): array => ['id' => $mapping($row)];
        $first = $pager->paginate($pdo, $query, self::SCOPE, $bindings, $mapper);
        self::assertSame([['id' => 10], ['id' => 20]], $first->data);
        self::assertTrue($first->hasNext);
        self::assertNotNull($first->nextCursor);

        $pdo->exec('DELETE FROM `' . $table . '` WHERE id = 20');
        $second = $pager->paginate($pdo, $query, self::SCOPE, $bindings, $mapper, $first->nextCursor);
        self::assertSame([['id' => 30], ['id' => 40]], $second->data);
        self::assertTrue($second->hasNext);
        self::assertNotNull($second->nextCursor);
        $last = $pager->paginate($pdo, $query, self::SCOPE, $bindings, $mapper, $second->nextCursor);
        self::assertSame([['id' => 50]], $last->data);
        self::assertFalse($last->hasNext);
        self::assertNull($last->nextCursor);
        self::assertSame('[{"id":50}]', json_encode($last->data, JSON_THROW_ON_ERROR));

        $empty = $pager->paginate($pdo, $query, 'fixture:east:tenant-99', ['tenant' => 99, ...array_diff_key($bindings, ['tenant' => true])], $mapper);
        self::assertSame(['data' => [], 'hasNext' => false, 'nextCursor' => null], $empty->jsonSerialize());
    }

    #[DataProvider('prepareModes')]
    public function testActualDriverErrorLeavesHostTransactionAndPriorWriteIntact(bool $emulate): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        $identity = $this->scalar($pdo, 'SELECT CONNECTION_ID()');
        $autoCommit = $this->scalar($pdo, 'SELECT @@autocommit');
        $other = $this->connect();
        self::assertTrue($pdo->beginTransaction());
        try {
            self::insertRows($pdo, $table);
            $pager = self::pager();
            $bindings = ['tenant' => 1, 'active' => true, 'pin' => 'pinned', 'absent' => null];
            $page = $pager->paginate($pdo, self::query($table), self::SCOPE, $bindings, self::mapper());
            self::assertSame([['id' => 10], ['id' => 20]], $page->data);
            self::assertTrue($pdo->inTransaction());
            self::assertSame('0', $this->scalar($other, 'SELECT COUNT(*) FROM `' . $table . '`'));
            $bad = new KeysetQueryDescriptor('catalog', '1', $table, ['missing'], 't.tenant_id = :tenant', ['tenant']);
            $failure = self::failureOf(static fn() => $pager->paginate($pdo, $bad, self::SCOPE, ['tenant' => 1], self::mapper()));
            self::assertSame(1054, self::driverCode($failure));
            self::assertTrue($pdo->inTransaction());
            self::assertSame($identity, $this->scalar($pdo, 'SELECT CONNECTION_ID()'));
            self::assertSame($autoCommit, $this->scalar($pdo, 'SELECT @@autocommit'));
            self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
            self::assertSame($emulate, (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
            self::assertSame('6', $this->scalar($pdo, 'SELECT COUNT(*) FROM `' . $table . '`'));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        self::assertSame('0', $this->scalar($other, 'SELECT COUNT(*) FROM `' . $table . '`'));
    }

    #[DataProvider('prepareModes')]
    public function testSameRegistrationOnDistinctSourcesUsesHostScope(bool $emulate): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        $second = $this->connect();
        $second->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        self::insertRows($pdo, $table);
        $second->exec('CREATE TEMPORARY TABLE `' . $table . '` ('
            . 'id BIGINT UNSIGNED PRIMARY KEY, tenant_id INT, category VARCHAR(32), name VARCHAR(128), '
            . 'score INT, is_active TINYINT, created_at DATETIME) ENGINE=InnoDB');
        try {
            self::insertRows($second, $table);
            $second->exec('UPDATE `' . $table . '` SET id = id + 100');
            $bindings = ['tenant' => 1, 'active' => true, 'pin' => 'pinned', 'absent' => null];
            $query = self::query($table);
            $pager = self::pager();
            $page = $pager->paginate($pdo, $query, self::SCOPE, $bindings, self::mapper());
            self::assertNotNull($page->nextCursor);
            $otherScope = 'fixture:west:tenant-1';
            $other = $pager->paginate($second, $query, $otherScope, $bindings, self::mapper());
            self::assertSame([['id' => 110], ['id' => 120]], $other->data);
            $this->expectException(InvalidKeysetCursorException::class);
            $pager->paginate($second, $query, $otherScope, $bindings, self::mapper(), $page->nextCursor);
        } finally {
            $second->exec('DROP TEMPORARY TABLE `' . $table . '`');
        }
    }

    #[DataProvider('prepareModes')]
    public function testMaximumAndOutOfRangeIdsFromTheActualDriver(bool $emulate): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        $max = (string) PHP_INT_MAX;
        $base = 'INSERT INTO `' . $table . '` (id, tenant_id, category, name, score, is_active, created_at) VALUES ';
        $values = ", 1, 'regular', 'row', 0, 1, '2020-01-01')";
        $pdo->exec($base . '(1' . $values . ', (' . $max . $values);
        $query = new KeysetQueryDescriptor('bounds', '1', $table, ['id'], 't.tenant_id = :tenant', ['tenant']);
        $pager = self::pager();
        $first = $pager->paginate($pdo, $query, self::SCOPE, ['tenant' => 1], self::mapper(), limit: 1);
        self::assertSame([['id' => 1]], $first->data);
        self::assertNotNull($first->nextCursor);
        $last = $pager->paginate($pdo, $query, self::SCOPE, ['tenant' => 1], self::mapper(), cursor: $first->nextCursor, limit: 1);
        self::assertSame([['id' => PHP_INT_MAX]], $last->data);

        $pdo->exec($base . '(9223372036854775808' . $values);
        try {
            $pager->paginate($pdo, $query, self::SCOPE, ['tenant' => 1], self::mapper(), limit: 2);
            self::fail('The oversized lookahead must fail.');
        } catch (PaginationExecutionException $failure) {
            self::assertSame('Invalid keyset row id.', $failure->getMessage());
        }
        $pdo->exec('DELETE FROM `' . $table . '` WHERE id <= ' . $max);
        $this->expectException(PaginationExecutionException::class);
        $pager->paginate($pdo, $query, self::SCOPE, ['tenant' => 1], self::mapper());
    }

    /** @return iterable<string, array{bool}> */
    public static function prepareModes(): iterable
    {
        yield 'native' => [false];
        yield 'emulated' => [true];
    }

    private static function query(string $table): KeysetQueryDescriptor
    {
        return new KeysetQueryDescriptor(
            'catalog',
            '1',
            $table,
            ['id'],
            't.tenant_id = :tenant',
            ['tenant', 'active', 'pin', 'absent'],
            "(t.is_active = :active OR t.category = :pin) AND :absent IS NULL AND t.name <> ':ordinary'",
        );
    }

    private static function pager(): PdoKeysetPaginator
    {
        return new PdoKeysetPaginator('test', [['id' => 'test', 'secret' => str_repeat('q', 32)]], 2);
    }

    private static function insertRows(PDO $pdo, string $table): void
    {
        $pdo->exec('INSERT INTO `' . $table . '` (id, tenant_id, category, name, score, is_active, created_at) VALUES '
            . "(10,1,'normal','first',0,1,'2020-01-01'), (20,1,'normal','second',0,1,'2020-01-01'), "
            . "(25,2,'pinned','unauthorized',0,1,'2020-01-01'), (30,1,'normal','third',0,1,'2020-01-01'), "
            . "(40,1,'pinned','fourth',0,0,'2020-01-01'), (50,1,'normal','fifth',0,1,'2020-01-01')");
    }

    /** @param array<array-key, mixed> $row */
    private static function mapId(array $row): int
    {
        $value = $row['id'];
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    /** @return Closure(array<string, mixed>): array{id: int} */
    private static function mapper(): Closure
    {
        return static fn(array $row): array => ['id' => self::mapId($row)];
    }
}
