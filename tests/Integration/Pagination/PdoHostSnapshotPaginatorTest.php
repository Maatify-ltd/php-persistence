<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Closure;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
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
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use SensitiveParameterValue;
use Throwable;

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
        self::assertSame(Stage::NEW, $broken->state());
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

        self::assertSame(Stage::TERMINAL, $broken->state());
        self::assertSame(Disposition::RETURNED_TO_HOST, $broken->settlement()?->disposition);
        self::assertSame(Reason::READ_FAILED, $broken->settlement()->reason);
        self::assertSame(Reason::READ_FAILED, $mapper->settlement()?->reason);
        self::assertSame(Reason::PAGE_COMPLETED, $success->settlement()?->reason);
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
            self::assertSame(Stage::RUNNING, $first->state());
            self::reject(fn() => $paginator->paginate($first));
            self::assertNull($first->settlement());
            self::reject($lease->rollBack(...));
            self::reject(fn() => $paginator->paginate($competingSameGrant));
            self::assertSame(Reason::GRANT_CONSUMED, $competingSameGrant->settlement()?->reason);
            self::reject(fn() => $paginator->paginate($competingOtherGrant));
            self::assertSame(Reason::BUSY, $competingOtherGrant->settlement()?->reason);
        };
        try {
            $paginator->paginate($first);
        } finally {
            $checkReentry = null;
        }
        $result = $first->settlement();
        self::assertSame(Reason::PAGE_COMPLETED, $result?->reason);
        self::reject(fn() => $paginator->paginate($first));
        self::assertSame($result, $first->settlement());
        $paginator->paginate($lease->newAttempt($inner));
        $stale = $lease->newAttempt($lease->authorizePage('inner', ['min' => 1], new PageRequest()));
        $lease->rollBack();
        $lease->beginRepeatableRead();
        self::reject(fn() => $paginator->paginate($stale));
        self::assertSame(Reason::STALE_CONTEXT, $stale->settlement()?->reason);
        $lease->rollBack();
        $lease->release();
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
            self::assertSame(Reason::STALE_CONTEXT, $attempt->settlement()?->reason);
        } finally {
            spl_autoload_unregister($loader);
            $lease->rollBack();
            $lease->release();
        }
    }

    public function testInvalidCountAfterCleanCursorAllowsHostToEndItsTransaction(): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([
            'invalid' => new HostSnapshotPagePlan("SELECT 'not a count'", 'SELECT 0', 'SELECT 1 AS id', [], [], [], $this->config(), static fn(array $row): array => $row),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $attempt = $lease->newAttempt($lease->authorizePage('invalid', [], new PageRequest()));
        try {
            new PdoHostSnapshotPaginator()->paginate($attempt);
            self::fail('Expected an invalid count.');
        } catch (PaginationExecutionException $error) {
            self::assertSame('Invalid pagination count value.', $error->getMessage());
        }
        self::assertSame(Reason::READ_FAILED, $attempt->settlement()?->reason);
        $lease->rollBack();
        $lease->release();
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
            new PaginationConfig(new SortWhitelist(['id' => 'id']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 2, 1, 50),
            $mapper ?? static fn(array $row): array => $row,
        );
    }

    private static function assertTraceDoesNotExposeDriver(Throwable $error): void
    {
        foreach ($error->getTrace() as $frame) {
            self::assertNoDriver($frame['args'] ?? []);
        }
    }

    private static function assertNoDriver(mixed $value): void
    {
        self::assertNotInstanceOf(PDO::class, $value);
        self::assertNotInstanceOf(PDOStatement::class, $value);
        if ($value instanceof SensitiveParameterValue) {
            self::assertNoDriver($value->getValue());
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                self::assertNoDriver($item);
            }
        }
    }

}
