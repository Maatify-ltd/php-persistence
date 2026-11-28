<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Closure;
use Fiber;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPageGrant;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPagePlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\HostSnapshotPageSettlementDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageAttemptStateEnum as Stage;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageDispositionEnum as Disposition;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum as Reason;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotPaginator;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;

final class PdoHostSnapshotPaginatorTest extends HostSnapshotIntegrationTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function prepareModes(): iterable
    {
        yield 'native' => [false];
        yield 'emulated' => [true];
    }

    #[DataProvider('prepareModes')]
    public function testAttemptsRetainTheirOwnSuccessOrFailure(bool $emulated): void
    {
        $writer = $this->connect();
        $table = $this->createTable($writer);
        $writer->exec("INSERT INTO $table VALUES (1, 10), (2, 20), (3, 30)");
        $mapperFailure = null;
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection($emulated), new HostSnapshotCatalog([
            'broken' => $this->plan($table, bad: true),
            'mapper' => $this->plan($table, static function () use (&$mapperFailure): never {
                $mapperFailure = new RuntimeException('Original mapper failure.');

                throw $mapperFailure;
            }),
            'page' => $this->plan($table),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $paginator = new PdoHostSnapshotPaginator();
        $broken = $lease->newAttempt($lease->authorizePage('broken', ['min' => 10], new PageRequest()));
        $mapper = $lease->newAttempt($lease->authorizePage('mapper', ['min' => 10], new PageRequest()));
        $success = $lease->newAttempt($lease->authorizePage('page', ['min' => 10], new PageRequest(2)));
        self::assertSame(Stage::New, $broken->state());
        self::assertNull(call_user_func($broken->settlement(...)));

        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $pdoFailure = self::failureOf(fn() => $paginator->paginate($broken));
            self::assertSame(1054, self::driverCode($pdoFailure));
            self::assertTraceDoesNotExposeDriver($pdoFailure);
            try {
                $paginator->paginate($mapper);
                self::fail('Expected the mapper to throw.');
            } catch (RuntimeException $error) {
                self::assertSame($mapperFailure, $error);
                self::assertContains('readAttempt', array_column($error->getTrace(), 'function'));
                self::assertTraceDoesNotExposeDriver($error);
            }
            $page = $paginator->paginate($success);
            self::assertSame(2, $page->page);
            self::assertSame(3, $page->total);
            self::assertEquals([['id' => 3, 'v' => 30]], $page->data);
            $lease->rollBack();
            $lease->release();
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }

        self::assertSame(Stage::Terminal, $broken->state());
        self::assertSame(Disposition::ReturnedToHost, $broken->settlement()?->disposition);
        self::assertSame(Reason::ReadFailed, $broken->settlement()->reason);
        self::assertSame(Reason::ReadFailed, $mapper->settlement()?->reason);
        self::assertSame(Reason::PageCompleted, $success->settlement()?->reason);
        self::assertSame([
            'disposition' => 'RETURNED_TO_HOST',
            'reason' => 'PAGE_COMPLETED',
        ], json_decode(json_encode($success->settlement(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMapperReentryCannotOverwriteTheOriginalAttemptOrFinishTheTransaction(): void
    {
        $writer = $this->connect();
        $table = $this->createTable($writer);
        $writer->exec("INSERT INTO $table VALUES (1, 10)");
        $paginator = new PdoHostSnapshotPaginator();
        $checkReentry = null;
        $catalog = new HostSnapshotCatalog([
            'outer' => $this->plan($table, static function (array $row) use (&$checkReentry): array {
                self::assertInstanceOf(Closure::class, $checkReentry);
                $checkReentry();

                return $row;
            }),
            'inner' => $this->plan($table),
        ]);
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), $catalog);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $outer = $lease->authorizePage('outer', ['min' => 1], new PageRequest());
        $inner = $lease->authorizePage('inner', ['min' => 1], new PageRequest());
        $first = $lease->newAttempt($outer);
        $competingSameGrant = $lease->newAttempt($outer);
        $competingOtherGrant = $lease->newAttempt($inner);
        $checkReentry = static function () use ($paginator, $lease, $first, $competingSameGrant, $competingOtherGrant): void {
            self::assertSame(Stage::Running, $first->state());
            self::reject(fn() => $paginator->paginate($first));
            self::assertNull($first->settlement());
            self::reject($lease->rollBack(...));
            self::reject(fn() => $paginator->paginate($competingSameGrant));
            self::assertSame(Reason::GrantConsumed, $competingSameGrant->settlement()?->reason);
            self::reject(fn() => $paginator->paginate($competingOtherGrant));
            self::assertSame(Reason::Busy, $competingOtherGrant->settlement()?->reason);
        };
        try {
            $paginator->paginate($first);
        } finally {
            $checkReentry = null;
        }
        $result = $first->settlement();
        self::assertSame(Reason::PageCompleted, $result?->reason);
        self::reject(fn() => $paginator->paginate($first));
        self::assertSame($result, $first->settlement());
        $paginator->paginate($lease->newAttempt($inner));
        $stale = $lease->newAttempt($lease->authorizePage('inner', ['min' => 1], new PageRequest()));
        $lease->rollBack();
        $lease->beginRepeatableRead();
        self::reject(fn() => $paginator->paginate($stale));
        self::assertSame(Reason::StaleContext, $stale->settlement()?->reason);
        $lease->rollBack();
        $lease->release();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFirstUseAutoloadCannotMoveAuthorizationToAnotherGeneration(): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([
            'empty' => new HostSnapshotPagePlan('SELECT 0', 'SELECT 0', 'SELECT 1 AS id', [], [], [], $this->config(), static fn(array $row): array => $row),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $changed = false;
        $loader = static function (string $class) use ($lease, &$changed): void {
            if ($class === HostSnapshotPageGrant::class) {
                $lease->rollBack();
                $lease->beginRepeatableRead();
                $changed = true;
            }
        };
        self::assertFalse(class_exists(HostSnapshotPageGrant::class, false));
        spl_autoload_register($loader, prepend: true);
        try {
            self::reject(fn() => $lease->authorizePage('empty', [], new PageRequest()));
            self::assertTrue($changed);
        } finally {
            spl_autoload_unregister($loader);
            $lease->rollBack();
            $lease->release();
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFirstUseAutoloadCannotAdmitAnOldGrantToANewGeneration(): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([
            'empty' => new HostSnapshotPagePlan('SELECT 0 AS `0`', 'SELECT 0', 'SELECT 1 AS id', [], [], [], $this->config(), static fn(array $row): array => $row),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $attempt = $lease->newAttempt($lease->authorizePage('empty', [], new PageRequest()));
        $changed = false;
        $loader = static function (string $class) use ($lease, &$changed): void {
            if ($class === HostSnapshotPageSettlementDTO::class) {
                $lease->rollBack();
                $lease->beginRepeatableRead();
                $changed = true;
            }
        };
        self::assertFalse(class_exists(HostSnapshotPageSettlementDTO::class, false));
        spl_autoload_register($loader, prepend: true);
        try {
            self::reject(fn() => new PdoHostSnapshotPaginator()->paginate($attempt));
            self::assertTrue($changed);
            self::assertSame(Reason::StaleContext, $attempt->settlement()?->reason);
        } finally {
            spl_autoload_unregister($loader);
            $lease->rollBack();
            $lease->release();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidCountQueries(): iterable
    {
        yield 'non-numeric count' => ["SELECT 'not a count'", 'Invalid pagination count value.'];
        yield 'count exceeds row bound' => ['SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3', 'The Host snapshot result exceeds its row limit.'];
    }

    #[DataProvider('invalidCountQueries')]
    public function testInvalidCountAfterCleanCursorAllowsHostToEndItsTransaction(string $countSql, string $message): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([
            'invalid' => new HostSnapshotPagePlan($countSql, 'SELECT 0', 'SELECT 1 AS id', [], [], [], $this->config(), static fn(array $row): array => $row),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $attempt = $lease->newAttempt($lease->authorizePage('invalid', [], new PageRequest()));
        try {
            new PdoHostSnapshotPaginator()->paginate($attempt);
            self::fail('Expected an invalid count.');
        } catch (PaginationExecutionException $error) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertSame(Reason::ReadFailed, $attempt->settlement()?->reason);
        $lease->rollBack();
        $lease->release();
    }

    #[DataProvider('prepareModes')]
    public function testCardinalityErrorDoesNotEndAConfirmedHostTransaction(bool $emulated): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection($emulated), new HostSnapshotCatalog([
            'invalid' => new HostSnapshotPagePlan(
                'SELECT (SELECT 1 UNION ALL SELECT 2)',
                'SELECT 0',
                'SELECT 1 AS id',
                [],
                [],
                [],
                $this->config(),
                static fn(array $row): array => $row,
            ),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $attempt = $lease->newAttempt($lease->authorizePage('invalid', [], new PageRequest()));
        $failure = self::failureOf(fn() => new PdoHostSnapshotPaginator()->paginate($attempt));
        self::assertSame(1242, self::driverCode($failure));
        self::assertSame(Reason::ReadFailed, $attempt->settlement()?->reason);
        $lease->rollBack();
        $lease->release();
    }

    #[DataProvider('prepareModes')]
    public function testDataExceptionDoesNotEndAConfirmedHostTransaction(bool $emulated): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection($emulated), new HostSnapshotCatalog([
            'invalid' => new HostSnapshotPagePlan(
                'SELECT CAST(:bound AS SIGNED) + 1',
                'SELECT 0',
                'SELECT 1 AS id',
                ['bound'],
                [],
                [],
                $this->config(),
                static fn(array $row): array => $row,
            ),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $attempt = $lease->newAttempt($lease->authorizePage('invalid', ['bound' => PHP_INT_MAX], new PageRequest()));
        $failure = self::failureOf(fn() => new PdoHostSnapshotPaginator()->paginate($attempt));
        self::assertSame(1690, self::driverCode($failure));
        self::assertSame(Reason::ReadFailed, $attempt->settlement()?->reason);
        $lease->rollBack();
        $lease->release();
    }

    public function testLostSessionDuringMappingSettlesBeforeTheHostCanDisposeIt(): void
    {
        $observer = $this->connect();
        $raw = $this->borrowableConnection();
        $id = (int) $this->scalar($raw, 'SELECT CONNECTION_ID()');
        $source = PdoHostSnapshotSource::attachExclusive($raw, new HostSnapshotCatalog([
            'one' => new HostSnapshotPagePlan(
                'SELECT 1',
                'SELECT 1',
                'SELECT 1 AS created_at, 1 AS id',
                [],
                [],
                [],
                $this->config(),
                static function (array $row): array {
                    Fiber::suspend();

                    return $row;
                },
            ),
        ]));
        unset($raw);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $attempt = $lease->newAttempt($lease->authorizePage('one', [], new PageRequest()));
        $reader = new Fiber(static fn() => new PdoHostSnapshotPaginator()->paginate($attempt));
        $reader->start();
        self::reject($source->discardForHost(...));
        $observer->exec('KILL CONNECTION ' . $id);
        self::failureOf($reader->resume(...));
        self::assertSame(Stage::Terminal, $attempt->state());
        $settlement = $attempt->settlement();
        self::assertNotNull($settlement);
        self::assertSame(Disposition::HostActionRequired, $settlement->disposition);
        self::assertSame(Reason::ScopeUnconfirmed, $settlement->reason);
        $source->discardForHost();
        self::assertSame($settlement, $attempt->settlement());
    }

    /** @param (Closure(array<string, mixed>): array<string, mixed>)|null $mapper */
    private function plan(string $table, ?Closure $mapper = null, bool $bad = false): HostSnapshotPagePlan
    {
        return new HostSnapshotPagePlan(
            $bad ? 'SELECT non_existing_field' : "SELECT COUNT(*) AS `0` FROM $table",
            "SELECT COUNT(*) FROM $table WHERE v >= :min",
            "SELECT id, v FROM $table WHERE v >= :min",
            [],
            ['min'],
            ['min'],
            new PaginationConfig(new SortWhitelist(['id' => 'id']), 'id', SortDirectionEnum::Asc, 'id', SortDirectionEnum::Asc, 2, 1, 50),
            $mapper ?? static fn(array $row): array => $row,
        );
    }
}
