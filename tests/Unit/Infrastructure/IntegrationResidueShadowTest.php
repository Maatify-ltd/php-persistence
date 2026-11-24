<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Infrastructure;

use IntegrationResidueShadow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function finishIntegrationResidueCheck;

require_once dirname(__DIR__, 3) . '/scripts/integration-support.php';

/**
 * @phpstan-import-type Residue from IntegrationResidueShadow
 * @phpstan-import-type ObservedEvent from IntegrationResidueShadow
 * @phpstan-import-type Binding from IntegrationResidueShadow
 */
final class IntegrationResidueShadowTest extends TestCase
{
    /** @return Residue */
    private static function row(string $id = '81', int $process = 12): array
    {
        return ['id' => $id, 'state' => 'RUNNING', 'started' => '2026-01-01 00:00:00', 'thread' => $process];
    }

    /** @return ObservedEvent */
    private static function event(string $state = 'ACTIVE', string $event = '5', string $thread = '22'): array
    {
        return ['thread' => $thread, 'instrumented' => true, 'event' => $event, 'state' => $state, 'end' => $state === 'ACTIVE' ? null : '9'];
    }

    /** @return Binding */
    private static function binding(bool $ended): array
    {
        return ['transaction' => '81', 'started' => self::row()['started'], 'thread' => '22', 'event' => '5',
            'endAcknowledged' => $ended, 'independentlyChecked' => $ended];
    }

    public function testMatchingWriterLockAndStableActiveEventProveLive(): void
    {
        $sample = [12 => self::event()];
        $locks = [['thread' => '22', 'transaction' => '81']];
        $writers = [['id' => '81', 'process' => 12, 'started' => self::row()['started']]];
        self::assertSame('LIVE', IntegrationResidueShadow::classify([self::row()], $sample, $sample, $locks, $writers)['state']);
        self::assertSame('UNKNOWN', IntegrationResidueShadow::classify([self::row()], $sample, $sample, $locks, [])['state']);
        self::assertSame('UNKNOWN', IntegrationResidueShadow::classify([self::row()], $sample, $sample, [], $writers)['state']);
    }

    public function testOwnedActiveEventCanBeLiveWithoutLocks(): void
    {
        $sample = [12 => self::event()];
        $row = self::row();
        self::assertSame('LIVE', IntegrationResidueShadow::classify([$row], $sample, $sample, [], [], [12 => self::binding(false)])['state']);
    }

    /** @return iterable<string, array{array<int, ObservedEvent>, array<int, ObservedEvent>, array<int, Binding>, string}> */
    public static function evidenceCases(): iterable
    {
        $ended = [12 => self::event('ROLLED BACK')];
        yield 'affirmative rollback' => [$ended, $ended, [12 => self::binding(true)], 'STALE_CONFIRMED'];
        $committed = [12 => self::event('COMMITTED')];
        yield 'affirmative commit' => [$committed, $committed, [12 => self::binding(true)], 'STALE_CONFIRMED'];
        yield 'last event without binding' => [$ended, $ended, [], 'UNKNOWN'];
        yield 'thread gone' => [[], [], [12 => self::binding(true)], 'UNKNOWN'];
        $reused = [12 => self::event('ROLLED BACK', '14')];
        yield 'same thread different event' => [$reused, $reused, [12 => self::binding(true)], 'UNKNOWN'];
        $reconnected = [12 => self::event('ROLLED BACK', thread: '31')];
        yield 'process ID reused by a different thread' => [$reconnected, $reconnected, [12 => self::binding(true)], 'UNKNOWN'];
        yield 'transaction ended during observation' => [[12 => self::event()], $ended, [12 => self::binding(true)], 'UNKNOWN'];
        $otherActive = $ended + [13 => self::event(thread: '23')];
        yield 'another active event without a lock' => [$otherActive, $otherActive, [12 => self::binding(true)], 'UNKNOWN'];
        $unobserved = $ended;
        $unobserved[12]['instrumented'] = false;
        yield 'thread instrumentation disabled' => [$unobserved, $unobserved, [12 => self::binding(true)], 'UNKNOWN'];
        $noEnd = $ended;
        $noEnd[12]['end'] = null;
        yield 'end event missing' => [$noEnd, $noEnd, [12 => self::binding(true)], 'UNKNOWN'];
        $unchecked = self::binding(true);
        $unchecked['independentlyChecked'] = false;
        yield 'independent corroboration missing' => [$ended, $ended, [12 => $unchecked], 'UNKNOWN'];
    }

    /**
     * @param array<int, ObservedEvent> $before
     * @param array<int, ObservedEvent> $after
     * @param array<int, Binding> $bindings
     */
    #[DataProvider('evidenceCases')]
    public function testClassifierRequiresCompleteCorrelatedEvidence(array $before, array $after, array $bindings, string $expected): void
    {
        self::assertSame($expected, IntegrationResidueShadow::classify([self::row()], $before, $after, [], [], $bindings)['state']);
    }

    public function testAggregatePreservesEachRowAndFailsClosed(): void
    {
        $ended = [12 => self::event('ROLLED BACK')];
        $rows = [self::row(), self::row('82', 13)];
        $bindings = [12 => self::binding(true)];
        $mixed = IntegrationResidueShadow::classify($rows, $ended, $ended, [], [], $bindings);
        self::assertSame('UNKNOWN', $mixed['state']);
        self::assertSame(['STALE_CONFIRMED', 'UNKNOWN'], array_column($mixed['transactions'], 'state'));

        $active = $ended + [13 => self::event(thread: '23')];
        $locks = [['thread' => '23', 'transaction' => '82']];
        $writers = [['id' => '82', 'process' => 13, 'started' => self::row()['started']]];
        $mixed = IntegrationResidueShadow::classify($rows, $active, $active, $locks, $writers, $bindings);
        self::assertSame('LIVE', $mixed['state']);
        self::assertSame(['UNKNOWN', 'LIVE'], array_column($mixed['transactions'], 'state'));
    }

    /** @return iterable<string, array{int, list<Residue>, string, string, int}> */
    public static function gateCases(): iterable
    {
        foreach (['LIVE', 'STALE_CONFIRMED', 'UNKNOWN'] as $state) {
            yield $state => [0, [self::row()], $state, $state, 1];
        }
        yield 'observer throws' => [0, [self::row()], 'throw', 'UNKNOWN', 1];
        yield 'tables with no transaction' => [1, [], 'UNKNOWN', 'UNKNOWN', 1];
        yield 'clean never observes' => [0, [], 'throw', '', 0];
    }

    /** @param list<Residue> $rows */
    #[DataProvider('gateCases')]
    public function testGateUsesOnlyOriginalSnapshot(int $tables, array $rows, string $diagnosis, string $printed, int $exit): void
    {
        $lines = [];
        $calls = 0;
        $result = finishIntegrationResidueCheck(
            2,
            $tables,
            $rows,
            static function () use ($diagnosis, &$calls): array {
                ++$calls;
                if ($diagnosis === 'throw') {
                    throw new RuntimeException('observer-private-details');
                }

                return ['state' => $diagnosis, 'transactions' => []];
            },
            static function (string $line, bool $error) use (&$lines): void {
                $lines[] = [$line, $error];
            },
        );

        self::assertSame($exit, $result);
        self::assertSame(['RESIDUE-CHECK pass=2 tables=' . $tables . ' openTransactions=' . count($rows), false], $lines[0]);
        if ($exit === 0) {
            self::assertSame(0, $calls);
            self::assertCount(1, $lines);
        } else {
            self::assertSame(1, $calls);
            self::assertStringContainsString('"state":"' . $printed . '"', $lines[1][0]);
            self::assertStringNotContainsString('observer-private-details', $lines[1][0]);
            self::assertTrue($lines[1][1]);
        }
    }
}
