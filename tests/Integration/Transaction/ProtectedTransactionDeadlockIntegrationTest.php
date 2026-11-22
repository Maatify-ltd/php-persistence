<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;

final class ProtectedTransactionDeadlockIntegrationTest extends DeadlockIntegrationTestCase
{
    public function testARealDeadlockFencesTheCheckoutWithoutReplay(): void
    {
        $checkout = PdoProtectedTransactionCheckout::attachExclusive(
            $this->unmonitoredConnection(),
            new ProtectedTransactionCommandCatalog([
                'lock' => new ProtectedTransactionCommandPlan($this->lockingReadSql(), ['id'], 1),
            ]),
        );
        $checkout->begin();
        try {
            $failure = $this->provokeDeadlockUsing(static function (int $id) use ($checkout): void {
                $checkout->runRegisteredCommand('lock', ['id' => $id]);
            });
            self::assertSame(1213, self::driverCode($failure));
            $settlement = $checkout->settlement();
            self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $settlement?->disposition);
            self::assertSame(ProtectedTransactionReasonEnum::OPERATION_UNCONFIRMED, $settlement->reason);
            self::assertNotEmpty($this->partnerReports());
            $this->expectException(ProtectedTransactionException::class);
            $checkout->runRegisteredCommand('lock', ['id' => 1]);
        } finally {
            $checkout->discardForHost();
        }
    }
}
