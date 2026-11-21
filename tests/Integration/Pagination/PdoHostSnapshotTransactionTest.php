<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCommandPlan;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotAccessModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum as State;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandEffectEnum as Effect;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandResultModeEnum as Mode;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use PHPUnit\Framework\Attributes\DataProvider;

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
            'view' => new HostSnapshotCommandPlan("SELECT v FROM $table WHERE id=:id", ['id'], Mode::BOUNDED_ROWS, 1, Effect::PRESERVES_TRANSACTION),
            'write' => new HostSnapshotCommandPlan("INSERT INTO $table VALUES (3, :v)", ['v'], Mode::AFFECTED_ROWS, effect: Effect::PRESERVES_TRANSACTION),
            'settings' => new HostSnapshotCommandPlan('SELECT CONNECTION_ID() AS id, @@completion_type AS completion', [], Mode::BOUNDED_ROWS, 1, Effect::PRESERVES_TRANSACTION),
        ]));
        unset($pdo);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        self::assertSame(State::TRANSACTION_ACTIVE, $lease->state());
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
        self::assertSame(State::IDLE, $lease->state());
        $settings = $lease->runRegisteredCommand('settings');
        self::assertIsArray($settings);
        self::assertSame($session, (string) $settings[0]['id']);
        self::assertSame($completion === 1 ? 'CHAIN' : 'RELEASE', (string) $settings[0]['completion']);
        self::assertSame($completion === 1 ? '1' : '0', $this->scalar($writer, "SELECT COUNT(*) FROM $table WHERE id=3"));
        $lease->release();
        $replacement = $source->checkout();
        $replacement->beginRepeatableRead(HostSnapshotAccessModeEnum::READ_ONLY);
        self::assertEquals([['v' => 20]], $replacement->runRegisteredCommand('view', ['id' => 1]));
        $replacement->rollBack();
        $replacement->release();
    }

    public function testCommandFailureRetainsOriginalDriverErrorAndQuarantinesSource(): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([], [
            'broken' => new HostSnapshotCommandPlan('SELECT non_existing_column', [], Mode::BOUNDED_ROWS, 1, Effect::PRESERVES_TRANSACTION),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $error = self::failureOf(fn () => $lease->runRegisteredCommand('broken'));
        self::assertSame(1054, self::driverCode($error));
        self::assertSame(State::QUARANTINED, $lease->state());
        $this->expectException(HostSnapshotPaginationException::class);
        $lease->release();
    }
}
