<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

/** Records explicit completion for the B1 fixtures without retaining a PDO alias. */
final class B1CheckoutLifecycle
{
    /** @var array<int, array{PdoProtectedTransactionCheckout, bool}> Checkout and confirmed begin. */
    private array $pending = [];

    /**
     * Records an outstanding checkout before any confirmed begin or handoff.
     */
    public function track(PdoProtectedTransactionCheckout $checkout): void
    {
        $this->pending[spl_object_id($checkout)] = [$checkout, false];
    }

    public function begin(PdoProtectedTransactionCheckout $checkout): void
    {
        $id = $this->tracked($checkout);
        $checkout->begin();
        $this->pending[$id][1] = true;
    }

    public function commit(PdoProtectedTransactionCheckout $checkout): void
    {
        $checkout->commit();
        $this->handoff($checkout, Reason::Committed);
    }

    public function rollBack(PdoProtectedTransactionCheckout $checkout): void
    {
        $checkout->rollBack();
        $this->handoff($checkout, Reason::RolledBack);
    }

    public function cancel(PdoProtectedTransactionCheckout $checkout): void
    {
        $id = $this->tracked($checkout);
        Assert::assertFalse($this->pending[$id][1]);
        $checkout->returnForHost();
        unset($this->pending[$id]);
        $settlement = $checkout->settlement();
        Assert::assertNotNull($settlement);
        Assert::assertSame(Disposition::Unchanged, $settlement->disposition);
        Assert::assertSame(Reason::CancelledBeforeQualification, $settlement->reason);
    }

    public function discard(PdoProtectedTransactionCheckout $checkout): void
    {
        $id = $this->tracked($checkout);
        $settlement = $checkout->settlement();
        Assert::assertNotNull($settlement);
        Assert::assertSame(Disposition::DiscardRequired, $settlement->disposition);
        $checkout->discardForHost();
        unset($this->pending[$id]);
        Assert::assertSame($settlement, $checkout->settlement());
    }

    /**
     * Records each unfinished checkout's state before attempting emergency
     * cleanup. If the operation already failed, leaves its primary throwable
     * to the caller. Otherwise reports the incomplete lifecycle even after
     * successful cleanup and includes the first cleanup failure when present.
     */
    public function finish(?Throwable $primary = null): void
    {
        $incomplete = [];
        $firstCleanupFailure = null;
        foreach ($this->pending as $id => [$checkout, $begun]) {
            $disposition = $checkout->settlement()?->disposition;
            $state = match ($disposition) {
                Disposition::Reusable => 'reusable without handoff',
                Disposition::DiscardRequired => 'discard required without discard',
                Disposition::Unchanged => 'cancelled without handoff',
                null => $begun ? 'active without verified end' : 'unstarted without cancellation',
            };
            $incomplete[] = 'checkout ' . $id . ' ' . $state;
            try {
                $this->emergencyRelease($checkout, $begun, $disposition);
            } catch (Throwable $cleanupFailure) {
                $firstCleanupFailure ??= $cleanupFailure;
            } finally {
                unset($this->pending[$id]);
            }
        }

        // The caller is already propagating the operation error, if any.
        if ($incomplete !== [] && $primary === null) {
            $message = 'Incomplete B1 fixture lifecycle: ' . implode(', ', $incomplete);
            if ($firstCleanupFailure !== null) {
                $message .= '; emergency cleanup failed (' . $firstCleanupFailure::class . ')';
            }
            throw new AssertionFailedError($message, 0, $firstCleanupFailure);
        }
    }

    private function emergencyRelease(PdoProtectedTransactionCheckout $checkout, bool $begun, ?Disposition $disposition): void
    {
        if ($disposition === Disposition::DiscardRequired) {
            $checkout->discardForHost();

            return;
        }
        if ($begun && $disposition === null) {
            try {
                $checkout->rollBack();
            } catch (Throwable $failure) {
                try {
                    $checkout->discardForHost();
                } catch (Throwable) {
                    // Retain the failure from the attempted rollback.
                }

                throw $failure;
            }
        }
        $checkout->returnForHost();
    }

    private function handoff(PdoProtectedTransactionCheckout $checkout, Reason $reason): void
    {
        $id = $this->tracked($checkout);
        $settlement = $checkout->settlement();
        Assert::assertNotNull($settlement);
        Assert::assertSame(Disposition::Reusable, $settlement->disposition);
        Assert::assertSame($reason, $settlement->reason);
        $checkout->returnForHost();
        unset($this->pending[$id]);
        Assert::assertSame($settlement, $checkout->settlement());
    }

    private function tracked(PdoProtectedTransactionCheckout $checkout): int
    {
        $id = spl_object_id($checkout);
        Assert::assertSame($checkout, $this->pending[$id][0] ?? null, 'Checkout is not pending in this B1 fixture.');

        return $id;
    }
}
