<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Proof;

use Closure;
use LogicException;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use Maatify\PhpPersistence\Tests\Integration\Support\PartnerProcess;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog as Catalog;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandPlan as Plan;
use Maatify\PhpPersistence\Transaction\Exception\ProtectedTransactionException;
use Maatify\PhpPersistence\Transaction\Repository\PdoProtectedTransactionCheckout as ProtectedCheckout;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use PDO;
use Pdo\Mysql;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/CheckoutProof.php';

final class VerifiedApplyDiscardTest extends IntegrationTestCase
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function hostCases(): iterable
    {
        foreach (['C1', 'C2'] as $mode) {
            foreach (['apply' => true, 'discard' => false] as $intent => $apply) {
                foreach (['commit' => true, 'rollback' => false] as $end => $commit) {
                    yield "$mode $intent then $end" => [$mode, $apply, $commit];
                }
            }
        }
    }

    #[DataProvider('hostCases')]
    public function testHostControlsFinalityAfterANormalResult(string $mode, bool $apply, bool $commit): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        $this->assertHostRows($checkout, [10]);
        $payload = $run($this->businessOperation($apply));
        self::assertSame($apply ? false : null, $payload);
        self::assertSame($apply ? 'PENDING_HOST' : 'LOCAL_UNDONE', $window->evidence()['effect']);
        self::assertNull($checkout->settlement());
        $pending = $apply ? [10, 20] : [10];
        $this->assertHostRows($checkout, $pending);
        self::assertSame([], $this->values($observer, $table));
        $commit ? $checkout->commit() : $checkout->rollBack();
        self::assertSame($commit ? $pending : [], $this->values($observer, $table));
        self::assertSame($commit ? 'COMMITTED' : 'ROLLED_BACK', $checkout->settlement()?->reason->name);
        self::assertSame('REUSABLE', $checkout->settlement()?->disposition->name);
        self::assertSame($writer, $checkout->returnForHost());
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised($checkout->returnForHost(...)));
    }

    public function testCurrentRunnerPreservesFalseWithoutDiscardingTheSuccessfulWrite(): void
    {
        [$observer, $table] = $this->fixture();
        $writer = $this->connect();
        $writer->beginTransaction();
        $writer->exec("INSERT INTO $table VALUES (1, 10)");
        self::assertFalse((new PdoSavepointTransactionRunner($writer))->run(static function () use ($writer, $table): bool {
            $writer->exec("INSERT INTO $table VALUES (2, 20)");

            return false;
        }));
        self::assertSame([10, 20], $this->values($writer, $table));
        self::assertSame([], $this->values($observer, $table));
        $writer->commit();
        self::assertSame([10, 20], $this->values($observer, $table));
    }

    /** @return iterable<string, array{bool}> */
    public static function decisions(): iterable
    {
        yield 'apply (false payload)' => [true];
        yield 'discard (null payload)' => [false];
    }

    #[DataProvider('decisions')]
    public function testOwnedCanDeliverAResultOnlyAfterItsOwnEnd(bool $apply): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        $checkout = new ParallelCheckout($writer, $catalog);
        $decide = $this->businessOperation($apply);

        self::assertSame($apply ? false : null, $checkout->runOwned(function (Closure $command) use ($observer, $table, $decide): Decision {
            $decision = $decide($command);
            self::assertSame([], $this->values($observer, $table));

            return $decision;
        }));
        self::assertSame($apply ? [20] : [], $this->values($observer, $table));
        self::assertSame($apply ? 'COMMITTED' : 'ROLLED_BACK', $checkout->ownedEffect);
        self::assertSame('REUSABLE', $checkout->settlement()?->disposition->name);
        self::assertSame($writer, $checkout->returnForHost());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function controlFaults(): iterable
    {
        foreach (['C1', 'C2'] as $mode) {
            foreach (['SAVEPOINT', 'ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT'] as $control) {
                foreach (['false', 'throw'] as $fault) {
                    yield "$mode $control $fault" => [$mode, $control, $fault];
                }
            }
        }
    }

    #[DataProvider('controlFaults')]
    public function testPredispatchFaultFencesWithoutReturningPayload(string $mode, string $control, string $fault): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        $primary = new RuntimeException('Fixture control failure.');
        try {
            $failure = self::raised(fn() => $run(
                $this->businessOperation(false),
                static function (string $stage) use ($control, $fault, $primary): bool {
                    if ($stage !== $control) {
                        return true;
                    }
                    if ($fault === 'throw') {
                        throw $primary;
                    }

                    return false;
                },
            ));
            if ($fault === 'throw') {
                self::assertSame($primary, $failure);
            } else {
                self::assertSame('Fixture control returned false before dispatch: ' . $control, $failure->getMessage());
            }
            self::assertSame($this->failureEvidence($control), $window->evidence());
            $before = $this->questions($writer);
            $this->assertFenced($checkout, $run);
            self::assertSame($before + 1, $this->questions($writer), 'Only the observing SHOW adds a query.');
            // Fixture-only inspection of a retired PDO. Product callers must not do this.
            self::assertSame($control === 'ROLLBACK TO SAVEPOINT' ? [10, 20] : [10], $this->values($writer, $table));
            self::assertSame([], $this->values($observer, $table));
        } finally {
            $checkout->discardForHost();
            $writer->rollBack(); // End the disposable fixture without trying to hand it back.
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function windowTransportFaults(): iterable
    {
        foreach (['C1', 'C2'] as $mode) {
            foreach (['SAVEPOINT', 'ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT'] as $target) {
                foreach (['before', 'after'] as $stage) {
                    yield "$mode $target $stage" => [$mode, $target, $stage];
                }
            }
        }
    }

    #[DataProvider('windowTransportFaults')]
    public function testRealControlReplyLossCannotDeliverANormalDiscard(string $mode, string $control, string $stage): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $relay = self::relay($control . ' ' . Window::SAVEPOINT, $stage);
        try {
            $writer = $this->relayedWriter($relay);
            [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
            self::failureOf(fn() => $run($this->businessOperation(false)));
            self::assertSame($stage === 'after' ? 'SERVER OK WITHHELD' : 'COMMAND NOT FORWARDED', $relay->readLine(12.0));
            self::assertSame($this->failureEvidence($control), $window->evidence());
            $this->assertFenced($checkout, $run);
            self::assertSame([], $this->values($observer, $table));
            $checkout->discardForHost();
        } finally {
            $relay->stop();
        }
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function releaseFaults(): iterable
    {
        foreach (['C1', 'C2'] as $mode) {
            yield "$mode apply release reply lost" => [$mode, true, 'after'];
            yield "$mode apply release accepted, confirmation lost" => [$mode, true, 'after-ack'];
            yield "$mode discard release accepted, confirmation lost" => [$mode, false, 'after-ack'];
        }
    }

    #[DataProvider('releaseFaults')]
    public function testReleaseAcknowledgementAloneDoesNotAllowANormalResult(string $mode, bool $apply, string $stage): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $relay = self::relay('RELEASE SAVEPOINT ' . Window::SAVEPOINT, $stage);
        try {
            $writer = $this->relayedWriter($relay);
            [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
            self::failureOf(fn() => $run($this->businessOperation($apply)));
            self::assertSame($stage === 'after' ? 'SERVER OK WITHHELD' : 'SERVER OK FORWARDED', $relay->readLine(12.0));
            self::assertSame([
                'effect' => $apply ? 'UNKNOWN' : 'LOCAL_UNDONE',
                'unconfirmed' => $stage === 'after' ? 'RELEASE SAVEPOINT' : null,
            ], $window->evidence());
            $this->assertFenced($checkout, $run);
            self::assertSame([], $this->values($observer, $table));
            $checkout->discardForHost();
        } finally {
            $relay->stop();
        }
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function completionFaults(): iterable
    {
        foreach (['C1', 'C2', 'C2 owned'] as $mode) {
            foreach (['commit' => true, 'rollback' => false] as $end => $commit) {
                foreach (['before', 'after', 'after-ack'] as $stage) {
                    yield "$mode $end $stage" => [$mode, $commit, $stage];
                }
            }
        }
    }

    #[DataProvider('completionFaults')]
    public function testTransportSeparatesEndAcknowledgementFromConnectionDisposition(string $mode, bool $commit, string $stage): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $verb = $commit ? 'COMMIT' : 'ROLLBACK';
        $relay = self::relay($verb . ' AND NO CHAIN NO RELEASE', $stage);
        try {
            $writer = $this->relayedWriter($relay);
            if ($mode === 'C2 owned') {
                $checkout = new ParallelCheckout($writer, $catalog);
                self::failureOf(fn() => $checkout->runOwned($this->businessOperation($commit)));
                self::assertSame($stage === 'after-ack' ? ($commit ? 'COMMITTED' : 'ROLLED_BACK') : 'UNKNOWN', $checkout->ownedEffect);
            } else {
                [$checkout, , $run] = $this->host($mode, $writer, $catalog);
                self::assertFalse($run($this->businessOperation(true)));
                self::assertSame([], $this->values($observer, $table));
                self::failureOf($commit ? $checkout->commit(...) : $checkout->rollBack(...));
            }

            self::assertSame(match ($stage) {
                'before' => 'COMMAND NOT FORWARDED',
                'after' => 'SERVER OK WITHHELD',
                'after-ack' => 'SERVER OK FORWARDED',
            }, $relay->readLine(12.0));
            if ($checkout instanceof ParallelCheckout) {
                self::assertSame($stage === 'after-ack' ? ($commit ? 'COMMITTED' : 'ROLLED_BACK') : 'UNKNOWN', $checkout->endEffect());
            }
            $settlement = $checkout->settlement();
            self::assertSame('DISCARD_REQUIRED', $settlement?->disposition->name);
            self::assertSame(match (true) {
                !$commit => 'ROLLBACK_UNCONFIRMED',
                $stage === 'after-ack' => 'COMMIT_CONFIRMED_CONNECTION_UNSAFE',
                default => 'COMMIT_OUTCOME_UNKNOWN',
            }, $settlement?->reason->name);
            $expected = $commit && $stage !== 'before' ? ($mode === 'C2 owned' ? [20] : [10, 20]) : [];
            self::assertSame($expected, $this->values($observer, $table));
            self::assertInstanceOf(ProtectedTransactionException::class, self::raised($checkout->returnForHost(...)));
            $checkout->discardForHost();
            self::assertSame($settlement, $checkout->settlement());
        } finally {
            $relay->stop();
        }
    }

    public function testOwnedThrowRetainsIdentityWhenRollbackReplyIsWithheld(): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $relay = self::relay('ROLLBACK AND NO CHAIN NO RELEASE', 'after');
        try {
            $checkout = new ParallelCheckout($this->relayedWriter($relay), $catalog);
            $primary = new LogicException('Domain failure after owned write.');
            $raised = self::raised(fn() => $checkout->runOwned(static function (Closure $command) use ($primary): never {
                $command('insert', ['id' => 2, 'value' => 20]);

                throw $primary;
            }));
            self::assertSame($primary, $raised);
            self::assertSame('SERVER OK WITHHELD', $relay->readLine(12.0));
            self::assertSame([], $this->values($observer, $table));
            self::assertSame('UNKNOWN', $checkout->ownedEffect);
            self::assertSame('ROLLBACK_UNCONFIRMED', $checkout->settlement()?->reason->name);
            self::assertSame('DISCARD_REQUIRED', $checkout->settlement()?->disposition->name);
            self::assertInstanceOf(ProtectedTransactionException::class, self::raised($checkout->returnForHost(...)));
            $checkout->discardForHost();
        } finally {
            $relay->stop();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function hostModes(): iterable
    {
        yield 'C1' => ['C1'];
        yield 'C2' => ['C2'];
    }

    #[DataProvider('hostModes')]
    public function testReentryAndStaleScopeHaveNoAuthority(string $mode): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        $stale = null;
        $decide = $this->businessOperation(false);
        $run(function (Closure $command) use ($checkout, $run, $decide, &$stale): Decision {
            $stale = $command;
            self::assertInstanceOf(ProtectedTransactionException::class, self::raised($checkout->rollBack(...)));
            self::assertInstanceOf(ProtectedTransactionException::class, self::raised($checkout->returnForHost(...)));
            self::assertInstanceOf(LogicException::class, self::raised(fn() => $run($decide)));

            return $decide($command);
        });
        self::assertInstanceOf(Closure::class, $stale);
        $evidence = $window->evidence();
        $before = $this->questions($writer);
        self::assertInstanceOf(LogicException::class, self::raised(fn() => $stale('insert', ['id' => 3, 'value' => 30])));
        self::assertInstanceOf(LogicException::class, self::raised(fn() => $run($decide)));
        self::assertSame($before + 1, $this->questions($writer));
        self::assertSame($evidence, $window->evidence());
        $checkout->commit();
        self::assertSame([10], $this->values($observer, $table));
        $checkout->returnForHost();
    }

    #[DataProvider('hostModes')]
    public function testCleanupCannotReplaceTheOriginalCallbackThrowable(string $mode): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        $primary = new LogicException('Domain failure after a provisional write.');
        $secondary = new RuntimeException('Injected release failure.');
        try {
            $failure = self::raised(fn() => $run(
                static function (Closure $command) use ($primary): never {
                    $command('insert', ['id' => 2, 'value' => 20]);

                    throw $primary;
                },
                static function (string $control) use ($secondary): bool {
                    if ($control === 'RELEASE SAVEPOINT') {
                        throw $secondary;
                    }

                    return true;
                },
            ));
            self::assertSame($primary, $failure);
            self::assertSame(['effect' => 'LOCAL_UNDONE', 'unconfirmed' => 'RELEASE SAVEPOINT'], $window->evidence());
            self::assertSame([10], $this->values($writer, $table));
            self::assertSame([], $this->values($observer, $table));
            $this->assertFenced($checkout, $run);
        } finally {
            $checkout->discardForHost();
            $writer->rollBack();
        }
    }

    #[DataProvider('hostModes')]
    public function testTimeoutSwallowedByCallbackRemainsLatchedAndFences(string $mode): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        $writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $observer->exec("INSERT INTO $table VALUES (3, 30)");
        $observer->beginTransaction();
        $observer->exec("UPDATE $table SET v = 31 WHERE id = 3");
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        $seen = null;
        $calls = 0;
        try {
            $work = static function (Closure $command) use (&$seen, &$calls): Decision {
                ++$calls;
                $command('insert', ['id' => 2, 'value' => 20]);
                $seen = self::failureOf(fn() => $command('update', ['id' => 3, 'value' => 32]));
                self::assertSame($seen, self::raised(fn() => $command('visible')));

                return new Decision(Intent::APPLY, false);
            };
            $failure = self::failureOf(fn() => $run($work));
            self::assertSame($seen, $failure);
            self::assertSame(1205, self::driverCode($failure));
            self::assertSame(1, $calls);
            self::assertSame('UNKNOWN', $window->evidence()['effect']);
            $before = $this->questions($writer);
            $this->assertFenced($checkout, $run);
            self::assertSame($before + 1, $this->questions($writer));
        } finally {
            $checkout->discardForHost();
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
            $observer->rollBack();
        }
        self::assertSame([30], $this->values($observer, $table));
    }

    #[DataProvider('hostModes')]
    public function testEndingAndRestartingThroughAnIneligibleAliasCannotPassUndo(string $mode): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        try {
            $failure = self::failureOf(fn() => $run(function (Closure $command) use ($writer): Decision {
                $command('insert', ['id' => 2, 'value' => 20]);
                $session = $this->scalar($writer, 'SELECT CONNECTION_ID()');
                // Negative control. The Host contract prohibits retaining and using this alias.
                $writer->rollBack();
                $writer->beginTransaction();
                self::assertTrue($writer->inTransaction());
                self::assertSame($session, $this->scalar($writer, 'SELECT CONNECTION_ID()'));

                return new Decision(Intent::DISCARD, null);
            }));
            self::assertSame(1305, self::driverCode($failure));
            self::assertSame(['effect' => 'UNKNOWN', 'unconfirmed' => 'ROLLBACK TO SAVEPOINT'], $window->evidence());
            $this->assertFenced($checkout, $run);
            self::assertSame([], $this->values($observer, $table));
        } finally {
            $checkout->discardForHost();
            $writer->rollBack();
        }
    }

    #[DataProvider('hostModes')]
    public function testEntryProbeRejectsAnEndedTransactionBeforeWork(string $mode): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        // Negative control: simulate a lost outer transaction while the PDO remains usable.
        $writer->rollBack();
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised(fn() => $run($this->businessOperation(true))));
        self::assertFalse($window->entered());
        self::assertSame(['effect' => 'NOT_STARTED', 'unconfirmed' => null], $window->evidence());
        $this->assertFenced($checkout, $run);
        self::assertSame([], $this->values($observer, $table));
        $checkout->discardForHost();
    }

    #[DataProvider('hostModes')]
    public function testSameSessionAtEntryDoesNotDetectAnUnqualifiedRawRestart(string $mode): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        // Boundary test: a Host that bypasses its delegation can replace the generation
        // before a savepoint exists. No same-session probe can attest that transaction.
        $session = $this->scalar($writer, 'SELECT CONNECTION_ID()');
        $writer->rollBack();
        $writer->beginTransaction();
        self::assertTrue($writer->inTransaction());
        self::assertSame($session, $this->scalar($writer, 'SELECT CONNECTION_ID()'));
        self::assertFalse($run($this->businessOperation(true)));
        self::assertSame('PENDING_HOST', $window->evidence()['effect']);
        $checkout->commit();
        self::assertSame([20], $this->values($observer, $table), 'The original Host marker has already been lost.');
        $checkout->returnForHost();
    }

    #[DataProvider('hostModes')]
    public function testUnbufferedBoundFailureClosesCursorBeforeContinuing(string $mode): void
    {
        [$observer, $table, $catalog] = $this->fixture();
        $writer = $this->connect();
        $writer->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, false);
        [$checkout, $window, $run] = $this->host($mode, $writer, $catalog);
        $failure = self::raised(fn() => $run(static function (Closure $command): never {
            $command('insert', ['id' => 2, 'value' => 20]);
            $command('tooMany');

            throw new LogicException('The row bound should have prevented a normal result.');
        }));
        self::assertSame('The proof row bound was exceeded.', $failure->getMessage());
        self::assertSame('LOCAL_UNDONE', $window->evidence()['effect']);
        self::assertNull($checkout->settlement());
        $checkout->commit();
        self::assertSame([10], $this->values($observer, $table));
        $checkout->returnForHost();
    }

    public function testActualClaimAndExperimentCannotOverlapInEitherDirection(): void
    {
        [, , $catalog] = $this->fixture();
        $writer = $this->connect();
        $existing = ProtectedCheckout::attachExclusive($writer, $catalog);
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised(fn() => new ParallelCheckout($writer, $catalog)));
        self::assertSame($writer, $existing->returnForHost());

        $parallel = new ParallelCheckout($writer, $catalog);
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised(fn() => ProtectedCheckout::attachExclusive($writer, $catalog)));
        $parallel->discardForHost();
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised(fn() => ProtectedCheckout::attachExclusive($writer, $catalog)));
    }

    /** @return array{PDO, string, Catalog} */
    private function fixture(): array
    {
        $observer = $this->connect();
        $table = $this->createTable($observer, 'wub22');
        $catalog = new Catalog([
            'insert' => new Plan("INSERT INTO $table (id, v) VALUES (:id, :value)", ['id', 'value']),
            'read' => new Plan("SELECT v FROM $table WHERE id = :id", ['id'], 1),
            'visible' => new Plan("SELECT v FROM $table ORDER BY id", [], 2),
            'tooMany' => new Plan("SELECT v FROM $table ORDER BY id", [], 1),
            'update' => new Plan("UPDATE $table SET v = :value WHERE id = :id", ['id', 'value']),
        ]);

        return [$observer, $table, $catalog];
    }

    /**
     * @return array{ProtectedCheckout|ParallelCheckout, Window, Closure}
     */
    private function host(string $mode, PDO $writer, Catalog $catalog): array
    {
        if ($mode === 'C1') {
            $checkout = ProtectedCheckout::attachExclusive($writer, $catalog);
            $checkout->begin();
            self::assertSame(1, $checkout->runRegisteredCommand('insert', ['id' => 1, 'value' => 10]));
            $window = new Window($checkout, $catalog);

            return [$checkout, $window, static fn(Closure $work, ?Closure $fault = null): mixed => $window->run($work, beforeControl: $fault)];
        }

        $checkout = new ParallelCheckout($writer, $catalog);
        $checkout->beginHost();
        self::assertSame(1, $checkout->hostCommand('insert', ['id' => 1, 'value' => 10]));

        return [$checkout, $checkout->window, $checkout->runInHost(...)];
    }

    /** @return Closure(Closure): Decision */
    private function businessOperation(bool $apply): Closure
    {
        $limit = $apply ? 20 : 19;

        return static function (Closure $command) use ($limit): Decision {
            self::assertSame(1, $command('insert', ['id' => 2, 'value' => 20]));
            $row = $command('read', ['id' => 2]);
            self::assertSame([['v' => 20]], $row);

            return $row[0]['v'] <= $limit ? new Decision(Intent::APPLY, false) : new Decision(Intent::DISCARD, null);
        };
    }

    /** @param list<int> $expected */
    private function assertHostRows(ProtectedCheckout|ParallelCheckout $checkout, array $expected): void
    {
        $actual = $checkout instanceof ProtectedCheckout
            ? $checkout->runRegisteredCommand('visible')
            : $checkout->hostCommand('visible');
        self::assertSame(array_map(static fn(int $value): array => ['v' => $value], $expected), $actual);
    }

    private function assertFenced(ProtectedCheckout|ParallelCheckout $checkout, Closure $run): void
    {
        self::assertSame('DISCARD_REQUIRED', $checkout->settlement()?->disposition->name);
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised($checkout->commit(...)));
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised($checkout->rollBack(...)));
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised($checkout->returnForHost(...)));
        $hostCommand = $checkout instanceof ProtectedCheckout ? $checkout->runRegisteredCommand(...) : $checkout->hostCommand(...);
        self::assertInstanceOf(ProtectedTransactionException::class, self::raised(fn() => $hostCommand('visible')));
        self::assertInstanceOf(LogicException::class, self::raised(fn() => $run($this->businessOperation(true))));
    }

    /** @return array{effect: string, unconfirmed: string} */
    private function failureEvidence(string $control): array
    {
        return [
            'effect' => match ($control) {
                'SAVEPOINT' => 'NOT_STARTED',
                'RELEASE SAVEPOINT' => 'LOCAL_UNDONE',
                default => 'UNKNOWN',
            },
            'unconfirmed' => $control,
        ];
    }

    private function questions(PDO $pdo): int
    {
        $statement = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'");
        self::assertNotFalse($statement);
        $count = $statement->fetchColumn(1);
        $statement->closeCursor();
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private static function raised(Closure $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('Expected the proof operation to throw.');
    }

    private static function relay(string $target, string $stage): PartnerProcess
    {
        return PartnerProcess::start([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/tests/Integration/Support/SnapshotEndResponseProxy.php',
            $target,
            $stage,
        ]);
    }

    private function relayedWriter(PartnerProcess $relay): PDO
    {
        $ready = $relay->readLine(12.0);
        self::assertMatchesRegularExpression('/\AREADY 127\.0\.0\.1:\d+\z/', $ready);
        $dsn = 'mysql:host=127.0.0.1;port=' . substr($ready, strrpos($ready, ':') + 1) . ';dbname=' . self::setting('DATABASE');

        return new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]);
    }
}
