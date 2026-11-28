<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCommandPlan;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotPagePlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotAccessModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum as State;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandEffectEnum as Effect;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandResultModeEnum as Mode;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum as Reason;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotRetirementDispositionEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotRowLimitExceededException;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotPaginator;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class PdoHostSnapshotTransactionTest extends HostSnapshotIntegrationTestCase
{
    /** @return iterable<string, array{int, bool}> */
    public static function completions(): iterable
    {
        yield 'native-chain-commit' => [1, false];
        yield 'emulated-release-rollback' => [2, true];
    }

    #[DataProvider('completions')]
    public function testHostControlsActualRrAndExplicitCompletion(int $completion, bool $emulated): void
    {
        $writer = $this->connect();
        $table = $this->createTable($writer);
        $writer->exec("INSERT INTO $table VALUES (1, 10)");
        $pdo = $this->borrowableConnection($emulated);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->exec('SET SESSION completion_type=' . $completion);
        $session = $this->scalar($pdo, 'SELECT CONNECTION_ID()');
        $source = PdoHostSnapshotSource::attachExclusive($pdo, new HostSnapshotCatalog([], [
            'view' => new HostSnapshotCommandPlan("SELECT v FROM $table WHERE id=:id", ['id'], Mode::BoundedRows, 1, Effect::PreservesTransaction),
            'write' => new HostSnapshotCommandPlan("INSERT INTO $table VALUES (3, :v)", ['v'], Mode::AffectedRows, effect: Effect::PreservesTransaction),
            'settings' => new HostSnapshotCommandPlan('SELECT CONNECTION_ID() AS id, @@completion_type AS completion', [], Mode::BoundedRows, 1, Effect::PreservesTransaction),
        ]));
        unset($pdo);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        self::assertSame(State::TransactionActive, $lease->state());
        self::assertEquals([['v' => 10]], $lease->runRegisteredCommand('view', ['id' => 1]));
        $writer->exec("UPDATE $table SET v=20 WHERE id=1");
        self::assertEquals([['v' => 10]], $lease->runRegisteredCommand('view', ['id' => 1]));
        self::assertSame(1, $lease->runRegisteredCommand('write', ['v' => 30]));
        self::assertSame('0', $this->scalar($writer, "SELECT COUNT(*) FROM $table WHERE id=3"));
        if ($completion === 1) {
            $lease->commit();
        } else {
            $lease->rollBack();
        }
        self::assertSame(State::Idle, $lease->state());
        $settings = $lease->runRegisteredCommand('settings');
        self::assertIsArray($settings);
        self::assertIsScalar($settings[0]['id']);
        self::assertSame($session, (string) $settings[0]['id']);
        self::assertSame($completion === 1 ? 'CHAIN' : 'RELEASE', $settings[0]['completion']);
        self::assertSame($completion === 1 ? '1' : '0', $this->scalar($writer, "SELECT COUNT(*) FROM $table WHERE id=3"));
        $lease->release();
        $replacement = $source->checkout();
        $replacement->beginRepeatableRead(HostSnapshotAccessModeEnum::ReadOnly);
        self::assertEquals([['v' => 20]], $replacement->runRegisteredCommand('view', ['id' => 1]));
        $replacement->rollBack();
        $replacement->release();
    }

    public function testBoundedRowsPreserveNumericDriverColumnKeys(): void
    {
        $plan = new HostSnapshotCommandPlan('SELECT 1', [], Mode::BoundedRows, 1, Effect::PreservesTransaction);
        foreach ([false, true] as $emulated) {
            $source = PdoHostSnapshotSource::attachExclusive(
                $this->borrowableConnection($emulated),
                new HostSnapshotCatalog([], ['probe' => $plan]),
            );
            $lease = $source->checkout();
            $lease->beginRepeatableRead();

            self::assertEquals([[1 => 1]], $lease->runRegisteredCommand('probe'));
            self::assertSame(State::TransactionActive, $lease->state());
            $lease->rollBack();
            $lease->release();
        }
    }

    public function testCatalogAcceptsStringIdentifiersThatPhpNormalizesToArrayIntegers(): void
    {
        $catalog = new HostSnapshotCatalog(
            ['12' => $this->emptyPagePlan()],
            ['34' => new HostSnapshotCommandPlan('SELECT 1 AS v', [], Mode::BoundedRows, 1, Effect::PreservesTransaction)],
        );
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), $catalog);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();

        self::assertEquals([['v' => 1]], $lease->runRegisteredCommand('34'));
        $page = $lease->newAttempt($lease->authorizePage('12', [], new PageRequest()));
        self::assertSame([], new PdoHostSnapshotPaginator()->paginate($page)->data);
        $lease->rollBack();
        $lease->release();
    }

    public function testBoundedRowsOverflowClosesTheResourceWithoutEndingTheHostTransaction(): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([], [
            'many' => new HostSnapshotCommandPlan('SELECT 1 AS v UNION ALL SELECT 2', [], Mode::BoundedRows, 1, Effect::PreservesTransaction),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        try {
            $lease->runRegisteredCommand('many');
            self::fail('Expected bounded rows overflow.');
        } catch (HostSnapshotPaginationException $error) {
            self::assertSame('The Host snapshot result exceeds its row limit.', $error->getMessage());
        }
        self::assertSame(State::TransactionActive, $lease->state());
        $lease->rollBack();
        $lease->release();
    }

    public function testRegisteredChainInvalidatesAQueuedPageOnTheSameSession(): void
    {
        $pdo = $this->borrowableConnection();
        $id = $this->scalar($pdo, 'SELECT CONNECTION_ID()');
        $source = PdoHostSnapshotSource::attachExclusive($pdo, new HostSnapshotCatalog(
            ['page' => $this->emptyPagePlan()],
            ['chain' => new HostSnapshotCommandPlan('ROLLBACK AND CHAIN NO RELEASE', [], Mode::AffectedRows)],
        ));
        unset($pdo);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $queued = $lease->newAttempt($lease->authorizePage('page', [], new PageRequest()));
        $lease->runRegisteredCommand('chain');

        self::assertSame(State::TransactionUnqualified, $lease->state());
        self::reject(fn() => new PdoHostSnapshotPaginator()->paginate($queued));
        self::assertSame(Reason::StaleContext, $queued->settlement()?->reason);
        self::reject(fn() => $lease->authorizePage('page', [], new PageRequest()));
        $lease->rollBack();
        $returned = $source->retireForHost();
        self::assertSame(HostSnapshotRetirementDispositionEnum::ReturnedForReconciliation, $returned->disposition);
        self::assertSame($id, $this->scalar($returned->pdo, 'SELECT CONNECTION_ID()'));
        self::assertFalse($returned->pdo->inTransaction());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHostCommandFencesAQueuedPageBeforeItsCursorIsReleased(): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog(
            ['page' => $this->emptyPagePlan()],
            ['many' => new HostSnapshotCommandPlan('SELECT 1 AS v UNION ALL SELECT 2', [], Mode::BoundedRows, 1, Effect::PreservesTransaction)],
        ));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $grant = $lease->authorizePage('page', [], new PageRequest());
        $queued = $lease->newAttempt($grant);
        $paginator = new PdoHostSnapshotPaginator();
        $loader = static function (string $class) use ($lease, $paginator, $queued): void {
            if ($class === HostSnapshotRowLimitExceededException::class) {
                self::assertSame(State::Busy, $lease->state());
                self::reject(fn() => $paginator->paginate($queued));
                self::assertSame(Reason::Busy, $queued->settlement()?->reason);
            }
        };

        // The first-use marker loads while the Host command still owns its cursor.
        self::assertFalse(class_exists(HostSnapshotRowLimitExceededException::class, false));
        spl_autoload_register($loader, prepend: true);
        try {
            self::reject(fn() => $lease->runRegisteredCommand('many'));
        } finally {
            spl_autoload_unregister($loader);
        }

        $retry = $lease->newAttempt($grant);
        self::assertSame([], $paginator->paginate($retry)->data);
        self::assertSame(Reason::PageCompleted, $retry->settlement()?->reason);
        $lease->rollBack();
        $lease->release();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOverflowRemainsThePrimaryFailureIfTheSessionIsLostDuringCleanup(): void
    {
        $observer = $this->connect();
        $pdo = $this->borrowableConnection();
        $id = (int) $this->scalar($pdo, 'SELECT CONNECTION_ID()');
        $source = PdoHostSnapshotSource::attachExclusive($pdo, new HostSnapshotCatalog([], [
            'many' => new HostSnapshotCommandPlan('SELECT 1 AS v UNION ALL SELECT 2', [], Mode::BoundedRows, 1, Effect::PreservesTransaction),
        ]));
        unset($pdo);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $loader = static function (string $class) use ($observer, $id): void {
            if ($class === HostSnapshotRowLimitExceededException::class) {
                $observer->exec('KILL CONNECTION ' . $id);
            }
        };

        self::assertFalse(class_exists(HostSnapshotRowLimitExceededException::class, false));
        spl_autoload_register($loader, prepend: true);
        try {
            $lease->runRegisteredCommand('many');
            self::fail('Expected the command to report its first failure.');
        } catch (HostSnapshotPaginationException $error) {
            self::assertSame('The Host snapshot result exceeds its row limit.', $error->getMessage());
        } finally {
            spl_autoload_unregister($loader);
        }
        self::assertSame(State::Quarantined, $lease->state());
        $source->discardForHost();
    }

    private function emptyPagePlan(): HostSnapshotPagePlan
    {
        return new HostSnapshotPagePlan(
            'SELECT 0',
            'SELECT 0',
            'SELECT 1 AS id',
            [],
            [],
            [],
            $this->config(),
            static fn(array $row): array => $row,
        );
    }

    public function testInitialPendingSettingCannotBeReturnedToThePoolUnresolved(): void
    {
        $pdo = $this->borrowableConnection();
        $pdo->exec('SET TRANSACTION READ ONLY');
        $source = PdoHostSnapshotSource::attachExclusive($pdo, new HostSnapshotCatalog([]));
        unset($pdo);
        $lease = $source->checkout();

        try {
            $lease->release();
            self::fail('An unresolved next-transaction setting escaped into the pool.');
        } catch (HostSnapshotPaginationException) {
            self::assertSame(State::Quarantined, $lease->state());
        }
    }

    public function testCommandFailureRetainsOriginalDriverErrorAndQuarantinesSource(): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([], [
            'broken' => new HostSnapshotCommandPlan('SELECT non_existing_column', [], Mode::BoundedRows, 1, Effect::PreservesTransaction),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $error = self::failureOf(fn() => $lease->runRegisteredCommand('broken'));
            self::assertSame(1054, self::driverCode($error));
            self::assertContains('executeCheckoutCommand', array_column($error->getTrace(), 'function'));
            self::assertTraceDoesNotExposeDriver($error);
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
        self::assertSame(State::Quarantined, $lease->state());
        $this->expectException(HostSnapshotPaginationException::class);
        $lease->release();
    }
}
