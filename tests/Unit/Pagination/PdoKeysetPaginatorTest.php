<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Pagination;

use Closure;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;
use Maatify\PhpPersistence\Pagination\Criteria\KeysetQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Exception\InvalidKeysetCursorException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoKeysetPaginator;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoKeysetPaginatorTest extends TestCase
{
    private const FIRST = 'SELECT t.`id`, t.`name`, t.`id` AS `__ks_id` FROM `entries` AS t'
        . ' WHERE (t.tenant_id = :tenant) AND (t.active = :active OR t.pinned = :pinned)'
        . ' ORDER BY t.`id` ASC LIMIT :__ks_limit';
    private const NEXT = 'SELECT t.`id`, t.`name`, t.`id` AS `__ks_id` FROM `entries` AS t'
        . ' WHERE (t.tenant_id = :tenant) AND (t.active = :active OR t.pinned = :pinned)'
        . ' AND t.`id` > :__ks_after ORDER BY t.`id` ASC LIMIT :__ks_limit';
    // Independent Python hashlib/hmac vector for the quoted SQL and typed bindings below.
    private const CURSOR = 'v1.old.MjA6M2FjNTllNTIzODAxN2YzOWIwYzU0MjYzY2ViODU3NDIwMjllZDFkODUxN2UyMGJjNGQzYTc3ZTZhYWFmZmY4Mw'
        . '.Mu7Cf9M-0pZod0y-YzhRVlGYu6aISBkY9Nn1_3TMhkw';
    private const OVERFLOW_CURSOR = 'v1.old.OTIyMzM3MjAzNjg1NDc3NTgwODozYWM1OWU1MjM4MDE3ZjM5YjBjNTQyNjNjZWI4NTc0MjAyOWVkMWQ4NTE3ZTIwYmM0ZDNhNzdlNmFhYWZmZjgz'
        . '.IMaksyGIKdpGGzjwUEHFAoIme5Nmv4jgB4Z4rkJOl_c';
    private const SCOPE = 'fixture:east:tenant-1';
    private const BINDINGS = ['tenant' => 1, 'pinned' => null, 'active' => true];

    public function testFirstPageGoldenAndContinuationForTheSameNormalizedQuery(): void
    {
        $pager = self::pager();
        $first = $this->statement([
            ['id' => 10, 'name' => 'first', '__ks_id' => '10'],
            ['id' => 20, 'name' => 'second', '__ks_id' => 20],
            ['id' => 30, 'name' => 'lookahead', '__ks_id' => 30],
        ]);
        $seen = [];
        $first->expects(self::exactly(4))->method('bindValue')->willReturnCallback(
            static function (string $name, mixed $value, int $type) use (&$seen): bool {
                $seen[] = [$name, $value, $type];

                return true;
            },
        );
        $closed = false;
        $first->expects(self::once())->method('closeCursor')->willReturnCallback(
            static function () use (&$closed): bool {
                $closed = true;

                return true;
            },
        );
        $pdo = $this->pdo();
        $pdo->expects(self::once())->method('prepare')->with(self::FIRST)->willReturn($first);
        $result = $pager->paginate($pdo, self::query(), self::SCOPE, self::BINDINGS, static function (array $row) use (&$closed): array {
            self::assertTrue($closed);
            self::assertArrayNotHasKey('__ks_id', $row);

            return ['label' => $row['name']];
        });
        self::assertSame([['label' => 'first'], ['label' => 'second']], $result->data);
        self::assertTrue($result->hasNext);
        self::assertSame(self::CURSOR, $result->nextCursor);
        self::assertSame([
            [':active', true, PDO::PARAM_BOOL],
            [':pinned', null, PDO::PARAM_NULL],
            [':tenant', 1, PDO::PARAM_INT],
            [':__ks_limit', 3, PDO::PARAM_INT],
        ], $seen);

        $next = $this->statement([['id' => 30, 'name' => 'last', '__ks_id' => 30], false]);
        $next->expects(self::once())->method('closeCursor')->willReturn(true);
        $next->expects(self::once())->method('errorCode')->willReturn('00000');
        $seenNext = [];
        $next->expects(self::exactly(5))->method('bindValue')->willReturnCallback(
            static function (string $name, mixed $value, int $type) use (&$seenNext): bool {
                $seenNext[] = [$name, $value, $type];

                return true;
            },
        );
        $nextPdo = $this->pdo();
        $nextPdo->expects(self::once())->method('prepare')->with(self::NEXT)->willReturn($next);
        $result = $pager->paginate($nextPdo, self::query(), self::SCOPE, array_reverse(self::BINDINGS, true), self::mapper(), self::CURSOR, 2);
        self::assertSame([['id' => 30, 'name' => 'last']], $result->data);
        self::assertFalse($result->hasNext);
        self::assertNull($result->nextCursor);
        self::assertSame([...$seen, [':__ks_after', 20, PDO::PARAM_INT]], $seenNext);
    }

    public function testOldCursorVerifiesWithItsKidAndContinuationUsesNumericActiveKid(): void
    {
        $pager = new PdoKeysetPaginator('123', [
            ['id' => 'old', 'secret' => str_repeat('a', 32)],
            ['id' => '123', 'secret' => str_repeat('b', 32)],
        ], 2);
        $next = $this->statement([
            ['id' => 30, 'name' => 'third', '__ks_id' => 30],
            ['id' => 40, 'name' => 'fourth', '__ks_id' => 40],
            ['id' => 50, 'name' => 'fifth', '__ks_id' => 50],
        ]);
        $next->method('bindValue')->willReturn(true);
        $next->expects(self::once())->method('closeCursor')->willReturn(true);
        $pdo = $this->pdo();
        $pdo->expects(self::once())->method('prepare')->with(self::NEXT)->willReturn($next);
        $page = $pager->paginate($pdo, self::query(), self::SCOPE, self::BINDINGS, self::mapper(), self::CURSOR);
        self::assertNotNull($page->nextCursor);
        self::assertStringStartsWith('v1.123.', $page->nextCursor);

        $last = $this->statement([['id' => 50, 'name' => 'fifth', '__ks_id' => 50], false]);
        $last->method('bindValue')->willReturn(true);
        $last->expects(self::once())->method('closeCursor')->willReturn(true);
        $last->expects(self::once())->method('errorCode')->willReturn('00000');
        $finalPdo = $this->pdo();
        $finalPdo->expects(self::once())->method('prepare')->with(self::NEXT)->willReturn($last);
        $newOnly = new PdoKeysetPaginator('123', [['id' => '123', 'secret' => str_repeat('b', 32)]], 2);
        self::assertFalse($newOnly->paginate($finalPdo, self::query(), self::SCOPE, self::BINDINGS, self::mapper(), $page->nextCursor)->hasNext);

        $reject = $this->pdo();
        $reject->expects(self::never())->method('prepare');
        $this->expectException(InvalidKeysetCursorException::class);
        $newOnly->paginate($reject, self::query(), self::SCOPE, self::BINDINGS, self::mapper(), self::CURSOR);
    }

    /** @param array<string, int|string|bool|null> $bindings */
    #[DataProvider('invalidCursorInputs')]
    public function testInvalidCursorFailsBeforePrepare(string $cursor, string $scope, array $bindings, int $limit, string $revision): void
    {
        $pdo = $this->pdo();
        $pdo->expects(self::never())->method('prepare');
        $this->expectException(InvalidKeysetCursorException::class);
        $this->expectExceptionMessage('Invalid keyset cursor.');
        self::pager()->paginate($pdo, self::query($revision), $scope, $bindings, self::mapper(), $cursor, $limit);
    }

    /** @return iterable<string, array{string, string, array<string, int|string|bool|null>, int, string}> */
    public static function invalidCursorInputs(): iterable
    {
        yield 'empty' => ['', self::SCOPE, self::BINDINGS, 2, '1'];
        yield 'tampered MAC' => [substr(self::CURSOR, 0, -1) . 'A', self::SCOPE, self::BINDINGS, 2, '1'];
        yield 'MAC alternative pad bits' => [substr(self::CURSOR, 0, -1) . 'x', self::SCOPE, self::BINDINGS, 2, '1'];
        yield 'oversize token' => [str_repeat('a', 257), self::SCOPE, self::BINDINGS, 2, '1'];
        yield 'unknown version' => ['v2' . substr(self::CURSOR, 2), self::SCOPE, self::BINDINGS, 2, '1'];
        yield 'signed integer overflow' => [self::OVERFLOW_CURSOR, self::SCOPE, self::BINDINGS, 2, '1'];
        yield 'different source' => [self::CURSOR, 'fixture:west:tenant-1', self::BINDINGS, 2, '1'];
        yield 'different limit' => [self::CURSOR, self::SCOPE, self::BINDINGS, 3, '1'];
        yield 'different registration revision' => [self::CURSOR, self::SCOPE, self::BINDINGS, 2, '2'];
        yield 'different bound type' => [self::CURSOR, self::SCOPE, ['tenant' => '1', 'active' => true, 'pinned' => null], 2, '1'];
        yield 'different bound value' => [self::CURSOR, self::SCOPE, ['tenant' => 2, 'active' => true, 'pinned' => null], 2, '1'];
    }

    /** @param array<string, mixed> $bindings */
    #[DataProvider('invalidBindings')]
    public function testBindingFailuresPrecedePrepare(array $bindings): void
    {
        $pdo = $this->pdo();
        $pdo->expects(self::never())->method('prepare');
        $this->expectException(InvalidPaginationQueryException::class);

        // @phpstan-ignore argument.type (Exercise runtime validation of the caller's binding map.)
        self::pager()->paginate($pdo, self::query(), self::SCOPE, $bindings, self::mapper());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBindings(): iterable
    {
        yield 'missing null' => [['active' => true, 'tenant' => 1]];
        yield 'extra' => [[...self::BINDINGS, 'extra' => 'value']];
        yield 'ineligible value' => [[...self::BINDINGS, 'tenant' => 1.0]];
        yield 'reserved' => [[...self::BINDINGS, '__KS_after' => 20]];
    }

    /** @param list<array{id: string, secret: string}> $keys */
    #[DataProvider('invalidConfig')]
    public function testInvalidConfiguration(string $active, array $keys, int $defaultLimit, int $maxLimit): void
    {
        $this->expectException(InvalidPaginationConfigurationException::class);

        new PdoKeysetPaginator($active, $keys, $defaultLimit, $maxLimit);
    }

    /** @return iterable<string, array{string, list<array{id: string, secret: string}>, int, int}> */
    public static function invalidConfig(): iterable
    {
        $key = ['id' => 'old', 'secret' => str_repeat('a', 32)];
        yield 'missing active' => ['other', [$key], 2, 10];
        yield 'invalid id' => ['invalid.id', [$key], 2, 10];
        yield 'duplicate id' => ['old', [$key, $key], 2, 10];
        yield 'short key' => ['old', [['id' => 'old', 'secret' => str_repeat('a', 31)]], 2, 10];
        yield 'zero default' => ['old', [$key], 0, 10];
        yield 'default exceeds maximum' => ['old', [$key], 11, 10];
        yield 'lookahead overflow' => ['old', [$key], 2, PHP_INT_MAX];
    }

    public function testOutOfPolicyLimitFailsBeforePrepare(): void
    {
        $pdo = $this->pdo();
        $pdo->expects(self::never())->method('prepare');
        $this->expectException(InvalidPaginationQueryException::class);
        self::pager()->paginate($pdo, self::query(), self::SCOPE, self::BINDINGS, self::mapper(), limit: 201);
    }

    /** @param list<array<string, mixed>|false> $rows */
    #[DataProvider('invalidRows')]
    public function testInvalidLookaheadOrFetchFailureDoesNotReachMapper(array $rows): void
    {
        $statement = $this->statement($rows);
        $statement->method('bindValue')->willReturn(true);
        $statement->method('errorCode')->willReturn('HY000');
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        $pdo = $this->pdo();
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $this->expectException(PaginationExecutionException::class);
        self::pager()->paginate($pdo, self::query(), self::SCOPE, self::BINDINGS, static fn(array $row): never => self::fail('Unexpected mapping.'));
    }

    /** @return iterable<string, array{list<array<string, mixed>|false>}> */
    public static function invalidRows(): iterable
    {
        $first = ['id' => 10, 'name' => 'first', '__ks_id' => 10];
        $second = ['id' => 20, 'name' => 'second', '__ks_id' => 20];
        yield 'duplicate lookahead' => [[$first, $second, $second]];
        yield 'overflow lookahead' => [[$first, $second, ['id' => 30, 'name' => 'third', '__ks_id' => (string) PHP_INT_MAX . '0']]];
        yield 'invalid exposed row' => [[['id' => 10, 'wrong_name' => 'first', '__ks_id' => 10]]];
        yield 'signed decimal not admitted' => [[['id' => 10, 'name' => 'first', '__ks_id' => '+10']]];
        yield 'fetch returned false with driver error' => [[false]];
    }

    public function testOriginalFailureSurvivesCleanupFailure(): void
    {
        $driver = new PDOException('Synthetic fetch failure.');
        $cleanup = new RuntimeException('Synthetic close failure.');
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('bindValue')->willReturn(true);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willThrowException($driver);
        $statement->expects(self::once())->method('closeCursor')->willThrowException($cleanup);
        $pdo = $this->pdo();
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);

        try {
            self::pager()->paginate($pdo, self::query(), self::SCOPE, self::BINDINGS, self::mapper());
            self::fail('Expected original PDO failure.');
        } catch (PDOException $thrown) {
            self::assertSame($driver, $thrown);
        }
    }

    public function testUnconfirmedClosurePreventsMappingAndResult(): void
    {
        $statement = $this->statement([['id' => 10, 'name' => 'first', '__ks_id' => 10], false]);
        $statement->method('bindValue')->willReturn(true);
        $statement->method('errorCode')->willReturn('00000');
        $statement->expects(self::once())->method('closeCursor')->willReturn(false);
        $pdo = $this->pdo();
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $this->expectException(PaginationExecutionException::class);
        self::pager()->paginate($pdo, self::query(), self::SCOPE, self::BINDINGS, static fn(array $row): never => self::fail('Unexpected mapping.'));
    }

    public function testBindingReferenceIsFrozenBeforeDriverAccess(): void
    {
        $tenant = 1;
        $bindings = ['tenant' => &$tenant, 'pinned' => null, 'active' => true];
        $statement = $this->statement([
            ['id' => 10, 'name' => 'first', '__ks_id' => 10],
            ['id' => 20, 'name' => 'second', '__ks_id' => 20],
            ['id' => 30, 'name' => 'lookahead', '__ks_id' => 30],
        ]);
        $statement->expects(self::exactly(4))->method('bindValue')->willReturnCallback(
            static function (string $name, mixed $value): bool {
                if ($name === ':tenant') {
                    self::assertSame(1, $value);
                }

                return true;
            },
        );
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        $pdo = $this->pdo();
        $pdo->expects(self::once())->method('prepare')->willReturnCallback(
            static function () use ($statement, &$tenant): PDOStatement {
                $tenant = 2;

                return $statement;
            },
        );

        self::assertSame(self::CURSOR, self::pager()->paginate($pdo, self::query(), self::SCOPE, $bindings, self::mapper())->nextCursor);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSigningFailureWithARealLookaheadReturnsNoPartialPage(): void
    {
        require dirname(__DIR__, 2) . '/Support/Pagination/keyset-signing-fault.php';
        $statement = $this->statement([
            ['id' => 10, 'name' => 'first', '__ks_id' => 10],
            ['id' => 20, 'name' => 'second', '__ks_id' => 20],
            ['id' => 30, 'name' => 'lookahead', '__ks_id' => 30],
        ]);
        $statement->method('bindValue')->willReturn(true);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        $pdo = $this->pdo();
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Synthetic cursor signing failure.');
        self::pager()->paginate($pdo, self::query(), self::SCOPE, self::BINDINGS, self::mapper());
    }

    public function testNormalDiagnosticsAndBothSerializationHooksProtectKeys(): void
    {
        $secret = 'fixture-secret-with-no-production-use';
        $pager = new PdoKeysetPaginator('old', [['id' => 'old', 'secret' => $secret]]);
        ob_start();
        var_dump($pager);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringNotContainsString('cursorProtocol', $dump);

        $class = PdoKeysetPaginator::class;
        $wire = 'O:' . strlen($class) . ':"' . $class . '":0:{}';
        foreach ([static fn(): string => serialize($pager), static fn(): mixed => unserialize($wire, ['allowed_classes' => [$class]])] as $attempt) {
            try {
                $attempt();
                self::fail('Expected serialization or restoration to be rejected.');
            } catch (PhpPersistenceExceptionInterface $failure) {
                self::assertInstanceOf(PaginationExecutionException::class, $failure);
                self::assertSame('Keyset paginator does not support serialization or restoration.', $failure->getMessage());
            }
        }
    }

    public function testConfigurationErrorRedactsSecretInBacktrace(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $secret = 'synthetic-short-secret';
        try {
            new PdoKeysetPaginator('old', [['id' => 'old', 'secret' => $secret]]);
            self::fail('Expected bad configuration.');
        } catch (InvalidPaginationConfigurationException $failure) {
            self::assertStringNotContainsString($secret, $failure->getMessage());
            self::assertStringNotContainsString($secret, print_r($failure->getTrace(), true));
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    private function pdo(): PDO&MockObject
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturnMap([
            [PDO::ATTR_DRIVER_NAME, 'mysql'],
            [PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION],
            [PDO::ATTR_CASE, PDO::CASE_NATURAL],
        ]);
        $pdo->expects(self::never())->method('setAttribute');
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::never())->method('exec');

        return $pdo;
    }

    /** @param list<array<string, mixed>|false> $rows */
    private function statement(array $rows): PDOStatement&MockObject
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->with(PDO::FETCH_ASSOC)->willReturnOnConsecutiveCalls(...$rows);

        return $statement;
    }

    private static function query(string $revision = '1'): KeysetQueryDescriptor
    {
        return new KeysetQueryDescriptor('catalog', $revision, 'entries', ['id', 'name'], 't.tenant_id = :tenant', ['tenant', 'active', 'pinned'], 't.active = :active OR t.pinned = :pinned');
    }

    private static function pager(): PdoKeysetPaginator
    {
        return new PdoKeysetPaginator('old', [['id' => 'old', 'secret' => str_repeat('a', 32)]], 2);
    }

    /** @return Closure(array<string, mixed>): array<array-key, mixed> */
    private static function mapper(): Closure
    {
        return static fn(array $row): array => $row;
    }
}
