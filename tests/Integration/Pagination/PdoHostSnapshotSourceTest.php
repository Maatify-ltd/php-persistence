<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\HostSnapshotCatalog;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCheckoutStateEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use PDO;
use WeakReference;

final class PdoHostSnapshotSourceTest extends HostSnapshotIntegrationTestCase
{
    public function testReleasedCheckoutNeverRecoversItsAuthority(): void
    {
        $source = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(), new HostSnapshotCatalog([]));
        $first = $source->checkout();
        self::assertSame(HostSnapshotCheckoutStateEnum::IDLE, $first->state());
        self::reject($source->checkout(...));
        self::reject(fn () => serialize($source));
        self::reject(fn () => serialize($first));
        $first->release();
        self::assertSame(HostSnapshotCheckoutStateEnum::RELEASED, $first->state());
        $second = $source->checkout();
        self::assertNotSame($first, $second);
        self::reject($first->release(...));
        self::assertSame(HostSnapshotCheckoutStateEnum::IDLE, $second->state());
        $second->release();
    }

    public function testSamePdoCannotAcquireASecondSource(): void
    {
        // The duplicate is deliberately out of contract. Keep the original borrow idle.
        $pdo = $this->borrowableConnection();
        $catalog = new HostSnapshotCatalog([]);
        $source = PdoHostSnapshotSource::attachExclusive($pdo, $catalog);
        self::reject(fn () => PdoHostSnapshotSource::attachExclusive($pdo, $catalog));
        $source->checkout()->release();
        self::reject(fn () => PdoHostSnapshotSource::attachExclusive($pdo, $catalog));
    }

    public function testRegistryDoesNotKeepThePdoAlive(): void
    {
        $pdo = $this->borrowableConnection();
        $weak = WeakReference::create($pdo);
        $source = PdoHostSnapshotSource::attachExclusive($pdo, new HostSnapshotCatalog([]));
        $source->checkout()->release();
        unset($pdo, $source);
        self::assertNull($weak->get());
    }

    public function testCustomDriverAndPersistentConnectionAreRejected(): void
    {
        $custom = new class () extends PDO {
            public function __construct() {}
        };
        $catalog = new HostSnapshotCatalog([]);
        self::reject(fn () => PdoHostSnapshotSource::attachExclusive($custom, $catalog));
        self::reject(fn () => PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(persistent: true), $catalog));
        $native = PdoHostSnapshotSource::attachExclusive($this->borrowableConnection(mysqlClass: true), $catalog);
        $native->checkout()->release();
    }

    public function testActiveTransactionCannotBeReleasedAsAnIdleCheckout(): void
    {
        // Simulate a Host violating the initial idle handoff. The source must refuse reuse.
        $pdo = $this->borrowableConnection();
        $source = PdoHostSnapshotSource::attachExclusive($pdo, new HostSnapshotCatalog([]));
        $checkout = $source->checkout();
        $pdo->exec('START TRANSACTION');
        try {
            self::reject($checkout->release(...));
            self::assertSame(HostSnapshotCheckoutStateEnum::QUARANTINED, $checkout->state());
            self::reject($source->checkout(...));
        } finally {
            $pdo->exec('ROLLBACK AND NO CHAIN NO RELEASE');
        }
    }

    private static function reject(callable $action): void
    {
        try {
            $action();
        } catch (HostSnapshotPaginationException) {
            self::assertTrue(true);

            return;
        }
        self::fail('Expected a Host snapshot contract rejection.');
    }
}
