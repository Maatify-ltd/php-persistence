<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use PDO;
use WeakReference;

final class PdoProtectedTransactionCheckoutTest extends IntegrationTestCase
{
    public function testAnUnqualifiedCheckoutReturnsUnchangedAndCannotBeReused(): void
    {
        $pdo = $this->connect();
        $catalog = new ProtectedTransactionCommandCatalog([]);
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog);
        self::assertUnsettled($checkout);
        $this->reject(static fn () => PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog));
        $this->reject(static fn () => $checkout->commit());
        self::assertSame($pdo, $checkout->returnForHost());
        self::assertSame(ProtectedTransactionDispositionEnum::UNCHANGED, $checkout->settlement()?->disposition);
        $this->reject(static fn () => $checkout->begin());
        $this->reject(static fn () => $checkout->returnForHost());
        $new = PdoProtectedTransactionCheckout::attachExclusive($pdo, $catalog);
        self::assertSame($pdo, $new->returnForHost());
    }

    public function testHostChoosesTransactionEndAndReceivesSameConnection(): void
    {
        $pdo = $this->connect();
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        $checkout->begin();
        self::assertTrue($pdo->inTransaction());
        self::assertUnsettled($checkout);
        $this->reject(static fn () => $checkout->returnForHost());
        $checkout->rollBack();
        self::assertFalse($pdo->inTransaction());
        $settlement = $checkout->settlement();
        self::assertSame(ProtectedTransactionDispositionEnum::REUSABLE, $settlement?->disposition);
        self::assertSame($pdo, $checkout->returnForHost());
        self::assertSame($settlement, $checkout->settlement());
    }

    public function testFailedServerQualificationFencesEvenAnUnstartedCheckout(): void
    {
        $pdo = $this->connect();
        $pdo->exec('SET SESSION autocommit = 0');
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        self::assertUnsettled($checkout);
        $this->reject(static fn () => $checkout->begin());
        $settlement = $checkout->settlement();
        self::assertSame(ProtectedTransactionDispositionEnum::DISCARD_REQUIRED, $settlement?->disposition);
        $this->reject(static fn () => $checkout->returnForHost());
        $this->reject(static fn () => $checkout->rollBack());
        $checkout->discardForHost();
        self::assertSame($settlement, $checkout->settlement());
    }

    public function testTheRegistryDoesNotKeepTheConnectionAliveAfterDiscard(): void
    {
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');
        $pdo = new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'));
        $connection = WeakReference::create($pdo);
        $checkout = PdoProtectedTransactionCheckout::attachExclusive($pdo, new ProtectedTransactionCommandCatalog([]));
        unset($pdo);
        $checkout->discardForHost();
        self::assertNull($connection->get());
    }

    private static function assertUnsettled(PdoProtectedTransactionCheckout $checkout): void
    {
        self::assertNull($checkout->settlement());
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
        } catch (ProtectedTransactionException) {
            return;
        }

        self::fail('Expected an illegal protected checkout operation to be rejected.');
    }
}
