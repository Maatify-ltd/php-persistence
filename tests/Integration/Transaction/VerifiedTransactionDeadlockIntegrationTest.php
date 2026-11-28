<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan;
use Maatify\PhpPersistence\Transaction\Command\VerifiedTransactionDecisionCommand;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionOutcomeEnum;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionReasonEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoVerifiedTransactionCheckout;
use Maatify\PhpPersistence\Transaction\Repository\VerifiedTransactionCommandsInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

final class VerifiedTransactionDeadlockIntegrationTest extends DeadlockIntegrationTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'Owned' => [true];
        yield 'Host' => [false];
    }

    #[DataProvider('modes')]
    public function testRealVictimNeverReplaysAndCannotHandBackTheConnection(bool $owned): void
    {
        $pdo = $this->unmonitoredConnection();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $checkout = PdoVerifiedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([
            'lock' => new ProtectedTransactionCommandPlan($this->lockingReadSql(), ['id'], 1),
        ]));
        $attempts = 0;
        $seen = null;
        $operation = function (VerifiedTransactionCommandsInterface $scope) use (&$attempts, &$seen): VerifiedTransactionDecisionCommand {
            ++$attempts;
            $seen = $this->provokeDeadlockUsing(static function (int $id) use ($scope): void {
                $scope->runRegisteredCommand('lock', ['id' => $id]);
            });
            return VerifiedTransactionDecisionCommand::discard();
        };
        try {
            if (!$owned) {
                $checkout->beginHost();
            }
            $caught = self::failureOf($owned ? fn() => $checkout->runOwned($operation) : fn() => $checkout->runInHost($operation));
            self::assertSame($seen, $caught);
            self::assertSame(1213, self::driverCode($caught));
            self::assertSame(1, $attempts);
            $settlement = $checkout->settlement();
            self::assertSame(VerifiedTransactionOutcomeEnum::Unknown, $settlement->operationOutcome);
            self::assertSame(VerifiedTransactionOutcomeEnum::Unknown, $settlement->transactionOutcome);
            self::assertSame(VerifiedTransactionReasonEnum::TransactionLost, $settlement->checkoutReason);
            self::assertSame(ProtectedTransactionDispositionEnum::DiscardRequired, $settlement->disposition);
            $this->expectException(ProtectedTransactionException::class);
            $checkout->returnForHost();
        } finally {
            $checkout->discardForHost();
        }
    }
}
