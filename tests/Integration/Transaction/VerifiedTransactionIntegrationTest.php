<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Transaction;

use Closure;
use Fiber;
use LogicException;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog as Catalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan as Plan;
use Maatify\PhpPersistence\Transaction\Command\VerifiedTransactionDecisionCommand as Decision;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionOutcomeEnum as Outcome;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout as ProtectedCheckout;
use Maatify\PhpPersistence\Transaction\Repository\PdoVerifiedTransactionCheckout as Checkout;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionCommandExecutor;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionConnection;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionDriver;
use Maatify\PhpPersistence\Transaction\Repository\ProtectedTransactionStatement;
use Maatify\PhpPersistence\Transaction\Repository\VerifiedTransactionCommandsInterface as Commands;
use PDO;
use PDOStatement;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use WeakReference;

final class VerifiedTransactionIntegrationTest extends IntegrationTestCase
{
    /** @return iterable<string, array{bool, bool, bool}> */
    public static function successCases(): iterable
    {
        foreach ([true, false] as $apply) {
            yield 'Owned ' . (int) $apply => [true, $apply, false];
            foreach ([true, false] as $commit) {
                yield 'Host ' . (int) $apply . '/' . (int) $commit => [false, $apply, $commit];
            }
        }
    }

    #[DataProvider('successCases')]
    public function testObservedEffectsAndSnapshotHistory(bool $owned, bool $apply, bool $commit): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $pdo = $this->connect();
        $checkout = Checkout::attachExclusive(pdo: $pdo, catalog: $catalog);
        $initial = $checkout->settlement();
        self::assertNull($initial->disposition);
        self::assertNull($initial->operationOutcome);
        self::assertSame($initial, $checkout->settlement());
        if (!$owned) {
            $this->beginWithMarker($checkout);
        }
        $payload = $apply ? false : new \stdClass();
        $work = function (Commands $scope) use ($checkout, $initial, $observer, $table, $apply, $payload): Decision {
            self::assertSame($initial, $checkout->settlement());
            self::assertSame(1, $scope->runRegisteredCommand('insert', ['id' => 2, 'value' => 20]));
            self::assertSame([['v' => 20]], $scope->runRegisteredCommand('read', ['id' => 2]));
            self::assertSame([], $this->values($observer, $table));

            return $apply ? Decision::apply($payload) : Decision::discard($payload);
        };
        self::assertSame($payload, $this->runMode($checkout, $owned, $work));
        $operation = $checkout->settlement();
        self::assertNull($operation->operationReason);
        self::assertNull($initial->operationOutcome);
        if ($owned) {
            self::assertSame($apply ? Outcome::Committed : Outcome::RolledBack, $operation->operationOutcome);
            self::assertSame($operation->operationOutcome, $operation->transactionOutcome);
            self::assertSame($apply ? [20] : [], $this->values($observer, $table));
        } else {
            $pending = $apply ? [10, 20] : [10];
            self::assertSame($apply ? Outcome::PendingHost : Outcome::LocalUndone, $operation->operationOutcome);
            self::assertNull($operation->transactionOutcome);
            self::assertNull($operation->disposition);
            self::assertSame(array_map(static fn(int $value): array => ['v' => $value], $pending), $checkout->runRegisteredCommand('visible'));
            self::assertSame([], $this->values($observer, $table));
            $commit ? $checkout->commit() : $checkout->rollBack();
            self::assertSame($commit ? $pending : [], $this->values($observer, $table));
            self::assertSame($operation->operationOutcome, $checkout->settlement()->operationOutcome);
            self::assertNull($operation->transactionOutcome);
        }
        $terminal = $checkout->settlement();
        self::assertSame(Disposition::Reusable, $terminal->disposition);
        self::assertSame($pdo, $checkout->returnForHost());
        $this->reject($checkout->returnForHost(...));
        self::assertSame($terminal, $checkout->settlement());
    }

    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'Owned' => [true];
        yield 'Host' => [false];
    }

    #[DataProvider('modes')]
    public function testCleanCallbackFailurePreservesIdentityAndUndo(bool $owned): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $checkout = Checkout::attachExclusive($this->connect(), $catalog);
        if (!$owned) {
            $this->beginWithMarker($checkout);
        }
        $primary = new LogicException('application failure');
        $caught = self::raised(fn() => $this->runMode($checkout, $owned, self::throwingWork($primary)));
        self::assertSame($primary, $caught);
        self::assertSame(Reason::CallbackFailed, $checkout->settlement()->operationReason);
        self::assertSame($owned ? Outcome::RolledBack : Outcome::LocalUndone, $checkout->settlement()->operationOutcome);
        if (!$owned) {
            self::assertSame([['v' => 10]], $checkout->runRegisteredCommand('visible'));
            $checkout->commit();
        }
        self::assertSame($owned ? [] : [10], $this->values($observer, $table));
        $checkout->returnForHost();
    }

    /** @return iterable<string, array{bool, string, string}> */
    public static function recoverableCommandFailures(): iterable
    {
        foreach (self::modes() as $mode => [$owned]) {
            yield "$mode unknown command" => [$owned, 'unregistered', 'Unknown protected transaction command.'];
            yield "$mode invalid bindings" => [$owned, 'read', 'Invalid protected transaction bindings.'];
            yield "$mode row bound" => [$owned, 'limited', 'The protected transaction result exceeds its row bound.'];
        }
    }

    #[DataProvider('recoverableCommandFailures')]
    public function testCaughtCommandFailureDoesNotExposeLiveExecutionHandles(bool $owned, string $command, string $message): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $pdo = $this->connect();
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);
        $checkout = Checkout::attachExclusive($pdo, $catalog);
        if (!$owned) {
            $this->beginWithMarker($checkout);
        }

        $payload = new \stdClass();
        $work = static function (Commands $scope) use ($owned, $command, $message, $payload): Decision {
            if ($owned) {
                self::assertSame(1, $scope->runRegisteredCommand('insert', ['id' => 1, 'value' => 10]));
            }
            self::assertSame(1, $scope->runRegisteredCommand('insert', ['id' => 2, 'value' => 20]));
            $failure = self::raised(fn() => $scope->runRegisteredCommand($command));
            self::assertInstanceOf(ProtectedTransactionException::class, $failure);
            self::assertSame($message, $failure->getMessage());
            self::assertNoExecutionHandlesInTrace($failure);

            self::assertSame([['v' => 20]], $scope->runRegisteredCommand('read', ['id' => 2]));
            return Decision::discard($payload);
        };
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        try {
            self::assertSame($payload, $this->runMode($checkout, $owned, $work));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }

        $operation = $checkout->settlement();
        self::assertSame($owned ? Outcome::RolledBack : Outcome::LocalUndone, $operation->operationOutcome);
        self::assertNull($operation->operationReason);
        self::assertSame($owned ? Disposition::Reusable : null, $operation->disposition);
        self::assertSame([], $this->values($observer, $table));
        if (!$owned) {
            self::assertSame([['v' => 10]], $checkout->runRegisteredCommand('visible'));
            $checkout->commit();
            self::assertSame([10], $this->values($observer, $table));
        }
        self::assertSame($pdo, $checkout->returnForHost());
    }

    /** @return iterable<string, array{bool, string}> */
    public static function caughtDriverCases(): iterable
    {
        foreach (self::modes() as $mode => [$owned]) {
            foreach (['throw', 'apply', 'discard'] as $afterDriver) {
                yield "$mode $afterDriver" => [$owned, $afterDriver];
            }
        }
    }

    #[DataProvider('caughtDriverCases')]
    public function testDriverFailureFencesButDoesNotReplaceEscapingApplicationError(bool $owned, string $afterDriver): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $pdo = $this->connect();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $checkout = Checkout::attachExclusive($pdo, $catalog);
        if (!$owned) {
            $this->beginWithMarker($checkout);
        }
        $observer->exec("INSERT INTO $table VALUES (3, 30)");
        $observer->beginTransaction();
        $observer->exec("UPDATE $table SET v = 31 WHERE id = 3");
        $primary = new LogicException('application failure after a caught driver error');
        $driver = null;
        $questionsAfterFailure = null;
        $work = function (Commands $scope) use ($pdo, $afterDriver, $primary, &$driver, &$questionsAfterFailure): Decision {
            $scope->runRegisteredCommand('insert', ['id' => 2, 'value' => 20]);
            $driver = self::failureOf(fn() => $scope->runRegisteredCommand('update', ['id' => 3, 'value' => 32]));
            self::assertSame(1205, self::driverCode($driver));
            self::assertNoExecutionHandlesInTrace($driver);
            $questionsAfterFailure = $this->questions($pdo);
            self::assertSame($driver, self::raised(fn() => $scope->runRegisteredCommand('visible')));

            return match ($afterDriver) {
                'throw' => throw $primary,
                'apply' => Decision::apply(false),
                default => Decision::discard(),
            };
        };
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        try {
            $caught = self::raised(fn() => $this->runMode($checkout, $owned, $work));
            self::assertSame($afterDriver === 'throw' ? $primary : $driver, $caught);
            self::assertSame(Outcome::Unknown, $checkout->settlement()->operationOutcome);
            self::assertSame(Outcome::Unknown, $checkout->settlement()->transactionOutcome);
            self::assertSame(Reason::CommandFailed, $checkout->settlement()->operationReason);
            $this->fenced($checkout, Reason::CommandFailed);
            self::assertSame($questionsAfterFailure + 1, $this->questions($pdo));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
            $checkout->discardForHost();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $observer->rollBack();
        }
        self::assertSame([30], $this->values($observer, $table));
    }

    /** @return iterable<string, array{bool, string, Reason, Outcome}> */
    public static function cleanupFaults(): iterable
    {
        yield 'Owned rollback' => [true, 'ROLLBACK AND NO CHAIN NO RELEASE', Reason::RollbackUnconfirmed, Outcome::Unknown];
        yield 'Host undo' => [false, 'ROLLBACK TO SAVEPOINT', Reason::RollbackToUnconfirmed, Outcome::Unknown];
        yield 'Host release after undo' => [false, 'RELEASE SAVEPOINT', Reason::ReleaseUnconfirmed, Outcome::LocalUndone];
    }

    #[DataProvider('cleanupFaults')]
    public function testCleanupFaultDoesNotReplaceCallbackException(bool $owned, string $target, Reason $reason, Outcome $operation): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $relay = self::relay($target, 'before');
        try {
            $checkout = Checkout::attachExclusive($this->relayed($relay), $catalog);
            if (!$owned) {
                $this->beginWithMarker($checkout);
            }
            $primary = new LogicException('application failure before cleanup');
            self::assertSame($primary, self::raised(fn() => $this->runMode($checkout, $owned, self::throwingWork($primary))));
            self::assertSame('COMMAND NOT FORWARDED', $relay->readLine(12.0));
            self::assertSame($operation, $checkout->settlement()->operationOutcome);
            $this->fenced($checkout, $reason);
            $checkout->discardForHost();
            self::assertSame([], $this->values($observer, $table));
        } finally {
            $relay->stop();
        }
    }

    /** @return iterable<string, array{bool, bool, string}> */
    public static function endFaults(): iterable
    {
        foreach (self::modes() as $mode => [$owned]) {
            foreach ([true, false] as $commit) {
                foreach (['before', 'after', 'after-ack'] as $stage) {
                    yield "$mode " . (int) $commit . " $stage" => [$owned, $commit, $stage];
                }
            }
        }
    }

    #[DataProvider('endFaults')]
    public function testEndAcknowledgementIsIndependentOfHandoff(bool $owned, bool $commit, string $stage): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $relay = self::relay(($commit ? 'COMMIT' : 'ROLLBACK') . ' AND NO CHAIN NO RELEASE', $stage);
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        try {
            $checkout = Checkout::attachExclusive($this->relayed($relay), $catalog);
            $work = self::work($owned ? $commit : true);
            if ($owned) {
                $failure = self::failureOf(fn() => $checkout->runOwned($work));
            } else {
                $this->beginWithMarker($checkout);
                self::assertFalse($checkout->runInHost($work));
                $failure = self::failureOf($commit ? $checkout->commit(...) : $checkout->rollBack(...));
            }
            self::assertNoExecutionHandlesInTrace($failure);
            self::assertSame(self::relayEvidence($stage), $relay->readLine(12.0));
            $reason = $stage === 'after-ack'
                ? Reason::HandoffUnconfirmed
                : ($commit ? Reason::CommitOutcomeUnknown : Reason::RollbackUnconfirmed);
            $outcome = $stage === 'after-ack' ? ($commit ? Outcome::Committed : Outcome::RolledBack) : Outcome::Unknown;
            self::assertSame($outcome, $checkout->settlement()->transactionOutcome);
            self::assertSame($owned ? $outcome : Outcome::PendingHost, $checkout->settlement()->operationOutcome);
            self::assertSame($owned ? $reason : null, $checkout->settlement()->operationReason);
            $this->fenced($checkout, $reason);
            self::assertSame($commit && $stage !== 'before' ? ($owned ? [20] : [10, 20]) : [], $this->values($observer, $table));
            $checkout->discardForHost();
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
            $relay->stop();
        }
    }

    /** @return iterable<string, array{string, string, Reason, Outcome}> */
    public static function windowFaults(): iterable
    {
        foreach ([
            'SAVEPOINT' => [Reason::SavepointUnconfirmed, Outcome::NotStarted],
            'ROLLBACK TO SAVEPOINT' => [Reason::RollbackToUnconfirmed, Outcome::Unknown],
            'RELEASE SAVEPOINT' => [Reason::ReleaseUnconfirmed, Outcome::LocalUndone],
        ] as $target => [$reason, $operation]) {
            foreach (['before', 'after', 'after-ack'] as $stage) {
                if ($stage === 'after-ack' && $target === 'ROLLBACK TO SAVEPOINT') {
                    $after = [Reason::ReleaseUnconfirmed, Outcome::LocalUndone];
                } elseif ($stage === 'after-ack') {
                    $after = [Reason::ContinuityUnconfirmed, $operation];
                } else {
                    $after = [$reason, $operation];
                }
                yield "$target $stage" => [$target, $stage, ...$after];
            }
        }
    }

    #[DataProvider('windowFaults')]
    public function testWindowControlFaultSuppressesDiscard(string $target, string $stage, Reason $reason, Outcome $operation): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $relay = self::relay($target, $stage);
        try {
            $checkout = Checkout::attachExclusive($this->relayed($relay), $catalog);
            $this->beginWithMarker($checkout);
            self::failureOf(fn() => $checkout->runInHost(self::work(false)));
            self::assertSame(self::relayEvidence($stage), $relay->readLine(12.0));
            self::assertSame($operation, $checkout->settlement()->operationOutcome);
            self::assertSame($reason, $checkout->settlement()->operationReason);
            self::assertSame(Outcome::Unknown, $checkout->settlement()->transactionOutcome);
            $this->fenced($checkout, $reason);
            self::assertSame([], $this->values($observer, $table));
            $checkout->discardForHost();
        } finally {
            $relay->stop();
        }
    }

    public function testClaimsAreSharedAcrossBothCheckoutsAndRetirementIsSticky(): void
    {
        [, , $catalog] = $this->fixture();
        $pdo = $this->connect();
        $protected = ProtectedCheckout::attachExclusive($pdo, $catalog);
        $this->reject(fn() => Checkout::attachExclusive($pdo, $catalog));
        self::assertSame($pdo, $protected->returnForHost());
        $checkout = Checkout::attachExclusive($pdo, $catalog);
        $this->reject(fn() => ProtectedCheckout::attachExclusive($pdo, $catalog));
        self::assertSame($pdo, $checkout->returnForHost());
        self::assertSame(Disposition::Unchanged, $checkout->settlement()->disposition);
        $abandoned = Checkout::attachExclusive($pdo, $catalog);
        unset($abandoned);
        $this->reject(fn() => ProtectedCheckout::attachExclusive($pdo, $catalog));
        $this->reject(fn() => Checkout::attachExclusive($pdo, $catalog));
    }

    /** @return iterable<string, array{bool, string}> */
    public static function starts(): iterable
    {
        foreach (self::modes() as $mode => [$owned]) {
            foreach (['before', 'after', 'after-ack'] as $stage) {
                yield "$mode $stage" => [$owned, $stage];
            }
        }
    }

    #[DataProvider('starts')]
    public function testUnconfirmedStartNeverAdmitsWork(bool $owned, string $stage): void
    {
        [, , $catalog] = $this->fixture();
        $relay = self::relay('START TRANSACTION READ WRITE', $stage);
        try {
            $checkout = Checkout::attachExclusive($this->relayed($relay), $catalog);
            $attempt = $owned ? fn() => $checkout->runOwned(static fn(): never => self::fail('Unexpected callback.')) : $checkout->beginHost(...);
            self::failureOf($attempt);
            self::assertSame(self::relayEvidence($stage), $relay->readLine(12.0));
            self::assertSame($owned ? Outcome::NotStarted : null, $checkout->settlement()->operationOutcome);
            self::assertSame($owned ? Reason::StartUnconfirmed : null, $checkout->settlement()->operationReason);
            self::assertSame(Outcome::Unknown, $checkout->settlement()->transactionOutcome);
            $this->fenced($checkout, Reason::StartUnconfirmed);
            $checkout->discardForHost();
        } finally {
            $relay->stop();
        }
    }

    public function testEligibilityAndLocalRejectionLeaveExistingProtectedModesAvailable(): void
    {
        [, , $catalog] = $this->fixture();
        $pdo = $this->connect();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $before = $this->questions($pdo);
        $this->reject(fn() => Checkout::attachExclusive($pdo, $catalog));
        self::assertSame($before + 1, $this->questions($pdo));
        $protected = ProtectedCheckout::attachExclusive($pdo, $catalog);
        self::assertSame($pdo, $protected->returnForHost());
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->beginTransaction();
        $this->reject(fn() => Checkout::attachExclusive($pdo, $catalog));
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->reject(fn() => Checkout::attachExclusive($pdo, $catalog));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $checkout = Checkout::attachExclusive($pdo, $catalog);
        $initial = $checkout->settlement();
        $before = $this->questions($pdo);
        $this->reject(fn() => $checkout->runInHost(self::work(true)));
        $this->reject($checkout->commit(...));
        $this->reject(fn() => $checkout->runRegisteredCommand('visible'));
        self::assertSame($before + 1, $this->questions($pdo));
        self::assertSame($initial, $checkout->settlement());
        $this->reject(fn() => serialize($checkout));
        $this->reject(fn() => $checkout->__unserialize([]));
        self::assertInstanceOf(\Error::class, self::raised(static fn() => clone($checkout)));
        self::assertSame($pdo, $checkout->returnForHost());
    }

    public function testFirstServerProbeRejectsAnOutOfContractActiveTransaction(): void
    {
        [, , $catalog] = $this->fixture();
        $pdo = $this->connect();
        $checkout = Checkout::attachExclusive($pdo, $catalog);
        // Negative control: a retained alias violates the Host's agreement.
        $pdo->exec('START TRANSACTION');
        try {
            $this->reject(fn() => $checkout->runOwned(static fn(): never => self::fail('Unexpected callback.')));
            self::assertSame(Outcome::NotStarted, $checkout->settlement()->operationOutcome);
            self::assertSame(Outcome::NotStarted, $checkout->settlement()->transactionOutcome);
            $this->fenced($checkout, Reason::QualificationFailed);
            self::assertTrue($pdo->inTransaction());
            $checkout->discardForHost();
        } finally {
            $pdo->rollBack();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function booleans(): iterable
    {
        yield 'yes' => [true];
        yield 'no' => [false];
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function fiberCases(): iterable
    {
        foreach (self::modes() as $mode => [$owned]) {
            foreach ([true, false] as $abandon) {
                yield "$mode " . (int) $abandon => [$owned, $abandon];
            }
        }
    }

    #[DataProvider('fiberCases')]
    public function testFiberContextReentryAndScopeRetirement(bool $owned, bool $abandon): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $pdo = $this->connect();
        $checkout = Checkout::attachExclusive($pdo, $catalog);
        if (!$owned) {
            $this->beginWithMarker($checkout);
        }
        $initial = $checkout->settlement();
        $work = function (Commands $scope) use ($checkout): Decision {
            self::assertSame(1, $scope->runRegisteredCommand('insert', ['id' => 2, 'value' => 20]));
            $this->reject($checkout->rollBack(...));
            Fiber::suspend($scope);
            return Decision::discard();
        };
        $fiber = new Fiber(fn() => $this->runMode($checkout, $owned, $work));
        $scope = $fiber->start();
        self::assertInstanceOf(Commands::class, $scope);
        self::assertSame($initial, $checkout->settlement());
        $before = $this->questions($pdo);
        $this->reject(fn() => $scope->runRegisteredCommand('visible'));
        $this->reject(fn() => $this->runMode($checkout, $owned, self::work(true)));
        $this->reject(fn() => ProtectedCheckout::attachExclusive($pdo, $catalog));
        $this->reject($checkout->returnForHost(...));
        $this->reject($checkout->discardForHost(...));
        self::assertSame($before + 1, $this->questions($pdo));
        $this->reject(fn() => serialize($scope));
        self::assertInstanceOf(\Error::class, self::raised(static fn() => clone($scope)));
        if ($abandon) {
            $weak = WeakReference::create($fiber);
            unset($fiber);
            gc_collect_cycles();
            self::assertNull($weak->get());
            $this->fenced($checkout, Reason::ContinuityUnconfirmed);
            $checkout->discardForHost();
            $pdo->rollBack();
            self::assertSame([], $this->values($observer, $table));
        } else {
            $fiber->resume();
            self::assertNull($fiber->getReturn());
            self::assertSame($owned ? Outcome::RolledBack : Outcome::LocalUndone, $checkout->settlement()->operationOutcome);
            if (!$owned) {
                $this->reject(fn() => $checkout->runInHost(self::work(true)));
                $checkout->commit();
            }
            $checkout->returnForHost();
            self::assertSame($owned ? [] : [10], $this->values($observer, $table));
        }
        $this->reject(fn() => $scope->runRegisteredCommand('visible'));
    }

    public function testUnbufferedOverflowClosesTheCursorBeforeVerifiedLocalUndo(): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $pdo = $this->connect();
        $pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);
        $checkout = Checkout::attachExclusive($pdo, $catalog);
        $this->beginWithMarker($checkout);
        $this->reject(fn() => $checkout->runInHost(static function (Commands $scope): Decision {
            $scope->runRegisteredCommand('insert', ['id' => 2, 'value' => 20]);
            $scope->runRegisteredCommand('limited');
            return Decision::apply();
        }));
        self::assertSame(Outcome::LocalUndone, $checkout->settlement()->operationOutcome);
        self::assertSame(Reason::CallbackFailed, $checkout->settlement()->operationReason);
        self::assertNull($checkout->settlement()->disposition);
        $checkout->commit();
        self::assertSame([10], $this->values($observer, $table));
        $checkout->returnForHost();
    }

    #[DataProvider('booleans')]
    public function testRawAliasReplacementIsAnExplicitNegativeControl(bool $during): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $pdo = $this->connect();
        $checkout = Checkout::attachExclusive($pdo, $catalog);
        $this->beginWithMarker($checkout);
        $replace = function () use ($pdo): void {
            $session = $this->scalar($pdo, 'SELECT CONNECTION_ID()');
            $pdo->rollBack();
            $pdo->beginTransaction();
            self::assertSame($session, $this->scalar($pdo, 'SELECT CONNECTION_ID()'));
            self::assertTrue($pdo->inTransaction());
        };
        if (!$during) {
            // An out-of-contract replacement before the savepoint cannot be attested.
            $replace();
            self::assertFalse($checkout->runInHost(self::work(true)));
            $checkout->commit();
            self::assertSame([20], $this->values($observer, $table));
            $checkout->returnForHost();
            return;
        }

        try {
            $failure = self::failureOf(fn() => $checkout->runInHost(static function (Commands $scope) use ($replace): Decision {
                $scope->runRegisteredCommand('insert', ['id' => 2, 'value' => 20]);
                $replace();
                return Decision::discard();
            }));
            self::assertSame(1305, self::driverCode($failure));
            $this->fenced($checkout, Reason::RollbackToUnconfirmed);
            self::assertSame([], $this->values($observer, $table));
            $checkout->discardForHost();
        } finally {
            $pdo->rollBack();
        }
    }

    public function testMariaDbSnapshotLossFencesAndMySqlControlRetainsHostOwnership(): void
    {
        [$observer, $table] = $this->fixture();
        $pdo = $this->dedicatedPdo(self::setting('PORT'));
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $mariaDb = self::engine() === 'mariadb';
        if ($mariaDb) {
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ON');
        }
        $observer->exec("INSERT INTO $table VALUES (1, 10), (2, 20)");
        $checkout = Checkout::attachExclusive($pdo, new Catalog([
            'snapshot' => new Plan("SELECT v FROM $table WHERE id = 2", [], 1),
            'lock' => new Plan("SELECT v FROM $table WHERE id = 2 FOR UPDATE", [], 1),
            'marker' => new Plan("UPDATE $table SET v = 12 WHERE id = 1", []),
        ]));
        unset($pdo);
        $checkout->beginHost();
        self::assertSame([['v' => 20]], $checkout->runRegisteredCommand('snapshot'));
        self::assertSame(1, $checkout->runRegisteredCommand('marker'));
        $observer->exec("UPDATE $table SET v = 21 WHERE id = 2");
        $work = static function (Commands $scope): Decision {
            self::assertSame([['v' => 21]], $scope->runRegisteredCommand('lock'));
            return Decision::apply(false);
        };
        if (!$mariaDb) {
            self::assertFalse($checkout->runInHost($work));
            $checkout->rollBack();
            self::assertSame([10, 21], $this->values($observer, $table));
            $checkout->returnForHost();
            return;
        }

        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $failure = self::failureOf(fn() => $checkout->runInHost($work));
            self::assertSame(1020, self::driverCode($failure));
            foreach ($failure->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $arg) {
                    self::assertNotInstanceOf(PDO::class, $arg);
                    self::assertNotInstanceOf(\PDOStatement::class, $arg);
                }
            }
            $this->fenced($checkout, Reason::TransactionLost);
            self::assertSame(Outcome::Unknown, $checkout->settlement()->transactionOutcome);
            self::assertSame([10, 21], $this->values($observer, $table));
            $checkout->discardForHost();
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    /** @return array{PDO, string, Catalog} */
    private function fixture(): array
    {
        $observer = $this->connect();
        $table = $this->createTable($observer, 'verified');
        return [$observer,
            $table,
            new Catalog([
                'insert' => new Plan("INSERT INTO $table (id, v) VALUES (:id, :value)", ['id', 'value']),
                'read' => new Plan("SELECT v FROM $table WHERE id = :id", ['id'], 1),
                'visible' => new Plan("SELECT v FROM $table ORDER BY id", [], 2),
                'limited' => new Plan("SELECT v FROM $table ORDER BY id", [], 1),
                'update' => new Plan("UPDATE $table SET v = :value WHERE id = :id", ['id', 'value']),
            ])];
    }

    private function beginWithMarker(Checkout $checkout): void
    {
        $checkout->beginHost();
        self::assertSame(1, $checkout->runRegisteredCommand('insert', ['id' => 1, 'value' => 10]));
    }

    /** @param Closure(Commands): Decision $work */
    private function runMode(Checkout $checkout, bool $owned, Closure $work): mixed
    {
        return $owned ? $checkout->runOwned($work) : $checkout->runInHost($work);
    }

    /** @return Closure(Commands): Decision */
    private static function work(bool $apply): Closure
    {
        return static function (Commands $scope) use ($apply): Decision {
            self::assertSame(1, $scope->runRegisteredCommand('insert', ['id' => 2, 'value' => 20]));
            return $apply ? Decision::apply(false) : Decision::discard();
        };
    }

    /** @return Closure(Commands): never */
    private static function throwingWork(Throwable $primary): Closure
    {
        return static function (Commands $scope) use ($primary): never {
            $scope->runRegisteredCommand('insert', ['id' => 2, 'value' => 20]);
            throw $primary;
        };
    }

    private function fenced(Checkout $checkout, Reason $reason): void
    {
        self::assertSame($reason, $checkout->settlement()->checkoutReason);
        self::assertSame(Disposition::DiscardRequired, $checkout->settlement()->disposition);
        $snapshot = $checkout->settlement();
        $this->reject($checkout->returnForHost(...));
        $this->reject($checkout->commit(...));
        $this->reject($checkout->rollBack(...));
        $this->reject(fn() => $checkout->runRegisteredCommand('visible'));
        self::assertSame($snapshot, $checkout->settlement());
    }

    private function questions(PDO $pdo): int
    {
        $statement = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'");
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn(1);
        $statement->closeCursor();
        self::assertIsNumeric($value);
        return (int) $value;
    }

    private static function assertNoExecutionHandlesInTrace(Throwable $failure): void
    {
        $trace = $failure->getTrace();
        self::assertNotEmpty(array_filter(array_column($trace, 'args')));
        foreach ($trace as $frame) {
            foreach ($frame['args'] ?? [] as $arg) {
                self::assertNotInstanceOf(PDO::class, $arg);
                self::assertNotInstanceOf(PDOStatement::class, $arg);
                self::assertNotInstanceOf(ProtectedTransactionConnection::class, $arg);
                self::assertNotInstanceOf(ProtectedTransactionStatement::class, $arg);
                self::assertNotInstanceOf(ProtectedTransactionCommandExecutor::class, $arg);
                self::assertNotInstanceOf(ProtectedTransactionDriver::class, $arg);
                self::assertNotInstanceOf(\SensitiveParameterValue::class, $arg);
            }
        }
    }

    private static function raised(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $error) {
            return $error;
        }
        self::fail('Expected an exception.');
    }

    private function reject(Closure $call): void
    {
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised($call));
    }

    private static function relay(string $target, string $stage): PartnerProcess
    {
        return PartnerProcess::start([PHP_BINARY, dirname(__DIR__) . '/Support/SnapshotEndResponseProxy.php', $target, $stage]);
    }

    private static function relayEvidence(string $stage): string
    {
        return match ($stage) {
            'before' => 'COMMAND NOT FORWARDED',
            'after' => 'SERVER OK WITHHELD',
            default => 'SERVER OK FORWARDED',
        };
    }

    private function relayed(PartnerProcess $relay): PDO
    {
        $ready = $relay->readLine(12.0);
        self::assertMatchesRegularExpression('/\AREADY 127\.0\.0\.1:\d+\z/', $ready);
        return $this->dedicatedPdo(substr($ready, strrpos($ready, ':') + 1));
    }

    private function dedicatedPdo(string $port): PDO
    {
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . $port . ';dbname=' . self::setting('DATABASE');

        return new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]);
    }
}
