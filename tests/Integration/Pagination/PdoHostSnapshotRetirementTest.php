<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCommandPlan;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum as State;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandEffectEnum as Effect;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandResultModeEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotRetirementDispositionEnum as Disposition;
use Maatify\PhpPersistence\Pagination\Repository\HostSnapshotConnection;
use Maatify\PhpPersistence\Pagination\Repository\HostSnapshotRetirement;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use PDO;
use ReflectionProperty;
use WeakReference;

final class PdoHostSnapshotRetirementTest extends HostSnapshotIntegrationTestCase
{
    public function testRetirementTransfersTheSameIdlePdoAndCannotBeReversed(): void
    {
        $pdo = $this->borrowableConnection();
        $reference = WeakReference::create($pdo);
        $catalog = new HostSnapshotCatalog([]);
        $source = PdoHostSnapshotSource::attachExclusive($pdo, $catalog);
        unset($pdo);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $lease->rollBack();

        $returned = $source->retireForHost();
        self::assertSame(Disposition::RETURNED_IDLE, $returned->disposition);
        self::assertSame($reference->get(), $returned->pdo);
        self::assertSame(HostSnapshotRetirement::class, $returned::class);
        self::assertNotContains(\JsonSerializable::class, new \ReflectionClass($returned)->getInterfaceNames());
        self::assertSame(State::RETIRED, $lease->state());
        self::assertFalse($returned->pdo->inTransaction());
        self::reject($source->checkout(...));
        self::reject($source->retireForHost(...));
        self::reject(fn() => PdoHostSnapshotSource::attachExclusive($returned->pdo, $catalog));
    }

    public function testRetirementWithActiveTransactionLeavesDispositionToHost(): void
    {
        $writer = $this->connect();
        $table = $this->createTable($writer);
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([], [
            'write' => new HostSnapshotCommandPlan("INSERT INTO $table VALUES (1, 10)", [], HostSnapshotCommandResultModeEnum::AFFECTED_ROWS, effect: Effect::PRESERVES_TRANSACTION),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $lease->runRegisteredCommand('write');
        $returned = $source->retireForHost();
        self::assertSame(Disposition::RETURNED_FOR_RECONCILIATION, $returned->disposition);
        self::assertTrue($returned->pdo->inTransaction());
        self::assertSame('0', $this->scalar($writer, "SELECT COUNT(*) FROM $table"));
        $returned->pdo->exec('COMMIT AND NO CHAIN NO RELEASE');
        self::assertSame('1', $this->scalar($writer, "SELECT COUNT(*) FROM $table"));
    }

    public function testUnresolvedResourcePreventsHandoffAndOnlyTheHostDiscards(): void
    {
        $writer = $this->connect();
        $table = $this->createTable($writer);
        $pdo = $this->borrowableConnection();
        $reference = WeakReference::create($pdo);
        $source = PdoHostSnapshotSource::attachExclusive($pdo, new HostSnapshotCatalog([], [
            'write' => new HostSnapshotCommandPlan("INSERT INTO $table VALUES (1, 10)", [], HostSnapshotCommandResultModeEnum::AFFECTED_ROWS, effect: Effect::PRESERVES_TRANSACTION),
        ]));
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $lease->runRegisteredCommand('write');

        // Inject an unresolved real cursor without adding a production fault hook.
        $connection = new ReflectionProperty(PdoHostSnapshotSource::class, 'connection')->getValue($source);
        self::assertInstanceOf(HostSnapshotConnection::class, $connection);
        $statement = $pdo->query("SELECT * FROM $table");
        self::assertNotFalse($statement);
        new ReflectionProperty(HostSnapshotConnection::class, 'statement')->setValue($connection, $statement);
        unset($pdo, $statement);

        self::reject($source->retireForHost(...));
        self::assertSame(State::QUARANTINED, $lease->state());
        self::assertInstanceOf(PDO::class, $reference->get());
        $source->discardForHost();
        self::assertSame(State::RETIRED, $lease->state());
        self::assertNull($reference->get());
        self::assertSame('0', $this->scalar($writer, "SELECT COUNT(*) FROM $table"));
        self::reject($source->checkout(...));
        self::reject($source->discardForHost(...));
    }

    public function testDiscardDoesNotProbeAConnectionLostByTheServer(): void
    {
        $admin = $this->connect();
        $pdo = $this->borrowableConnection();
        $sessionId = (int) $this->scalar($pdo, 'SELECT CONNECTION_ID()');
        $source = PdoHostSnapshotSource::attachExclusive($pdo, new HostSnapshotCatalog([]));
        unset($pdo);
        $lease = $source->checkout();
        $lease->beginRepeatableRead();
        $admin->exec('KILL CONNECTION ' . $sessionId);
        $source->discardForHost();
        self::assertSame(State::RETIRED, $lease->state());
    }

}
