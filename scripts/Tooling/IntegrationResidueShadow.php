<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tooling;

use PDO;
use PDOException;
use UnexpectedValueException;

/**
 * Diagnostics for the disposable Integration harness. The original residue
 * snapshot owns the gate decision. A later observation can only explain it.
 *
 * @phpstan-type Residue array{id: string, state: string, started: string, thread: int}
 * @phpstan-type ObservedEvent array{thread: string, instrumented: bool, event: ?string, state: ?string, end: ?string}
 * @phpstan-type Lock array{thread: string, transaction: string}
 * @phpstan-type Writer array{id: string, process: int, started: string}
 * @phpstan-type Binding array{transaction: string, started: string, thread: string, event: string, endAcknowledged: bool, independentlyChecked: bool}
 * @phpstan-type Observation array{id: string, process: int, thread: ?string, event: ?string, eventState: ?string, endEvent: ?string, state: 'LIVE'|'STALE_CONFIRMED'|'UNKNOWN', reason: string}
 * @phpstan-type Result array{state: 'LIVE'|'STALE_CONFIRMED'|'UNKNOWN', transactions: list<Observation>}
 */
final class IntegrationResidueShadow
{
    /**
     * Collects diagnostic evidence only when the MySQL observer has the
     * required transaction instrumentation. The runner has no pre-end
     * binding after PHPUnit exits. Controlled tests can supply one from an
     * owned session with a known lifecycle. An unsupported or incomplete
     * observation remains UNKNOWN and does not change the residue gate.
     *
     * @param list<Residue> $transactions
     * @param array<int, Binding> $bindings keyed by the controlled processlist ID
     * @return Result
     */
    public static function observe(PDO $observer, string $engine, array $transactions, array $bindings = []): array
    {
        if ($transactions === []) {
            return self::unknown($transactions, 'NO_TRANSACTION_ROWS');
        }
        if ($engine !== 'mysql') {
            return self::unknown($transactions, 'ENGINE_PROFILE_UNQUALIFIED');
        }

        try {
            if (!self::instrumented($observer)) {
                return self::unknown($transactions, 'TRANSACTION_INSTRUMENTATION_UNAVAILABLE');
            }

            $before = self::events($observer);
            $writers = self::writers($observer);
            $locks = self::locks($observer);
            $after = self::events($observer);

            return self::classify($transactions, $before, $after, $locks, $writers, $bindings);
        } catch (PDOException|UnexpectedValueException) {
            return self::unknown($transactions, 'OBSERVATION_UNAVAILABLE');
        }
    }

    /**
     * Classifies each residue using stable observations and exact identity
     * evidence. A controlled binding must match the transaction ID, start,
     * thread and event. Missing identity or instrumentation stays UNKNOWN.
     * Stale confirmation also needs acknowledged end, independent release
     * evidence and stable snapshots with no locks or active participants.
     * LIVE takes precedence in the aggregate, which is stale only when
     * every residue is confirmed stale.
     *
     * @param list<Residue> $transactions
     * @param array<int, ObservedEvent> $before keyed by the processlist ID
     * @param array<int, ObservedEvent> $after
     * @param list<Lock> $locks
     * @param list<Writer> $writers
     * @param array<int, Binding> $bindings
     * @return Result
     */
    public static function classify(array $transactions, array $before, array $after, array $locks, array $writers, array $bindings = []): array
    {
        $observations = [];
        // Any other active participant, missing instrumentation or moving
        // snapshot prevents confirming stale as an aggregate observation.
        $allEnded = $before === $after && $locks === [];
        foreach ($after as $event) {
            $allEnded = $allEnded && $event['instrumented'] && self::ended($event);
        }

        foreach ($transactions as $row) {
            $process = $row['thread'];
            $event = $after[$process] ?? null;
            $state = 'UNKNOWN';
            $reason = 'EVENT_UNAVAILABLE';

            if ($event !== null && $event['event'] !== null && $event['event'] !== '0' && $event['instrumented']) {
                $binding = $bindings[$process] ?? null;
                $sameEvent = $binding !== null && $binding['transaction'] === $row['id'] && $binding['started'] === $row['started']
                    && $binding['thread'] === $event['thread'] && $binding['event'] === $event['event'];

                if (($before[$process] ?? null) !== $event) {
                    $reason = 'EVENT_CHANGED_DURING_OBSERVATION';
                } elseif ($binding !== null && !$sameEvent) {
                    $reason = 'BOUND_EVENT_CHANGED';
                } elseif ($event['state'] === 'ACTIVE' && $event['end'] === null) {
                    $liveBinding = $sameEvent && !$binding['endAcknowledged'];
                    if ($liveBinding || ($binding === null && self::hasWriterLock($row, $event, $locks, $writers))) {
                        $state = 'LIVE';
                        $reason = $liveBinding ? 'BOUND_EVENT_ACTIVE' : 'ACTIVE_EVENT_WITH_MATCHING_WRITER_LOCK';
                    } else {
                        $reason = 'ACTIVE_EVENT_IDENTITY_UNPROVEN';
                    }
                } elseif (self::ended($event) && $sameEvent && $binding['endAcknowledged'] && $binding['independentlyChecked'] && $allEnded) {
                    $state = 'STALE_CONFIRMED';
                    $reason = 'BOUND_EVENT_END_AND_RELEASE_CONFIRMED';
                } else {
                    $reason = 'TRANSACTION_END_UNPROVEN';
                }
            }

            $observations[] = [
                'id' => $row['id'],
                'process' => $process,
                'thread' => $event['thread'] ?? null,
                'event' => $event['event'] ?? null,
                'eventState' => $event['state'] ?? null,
                'endEvent' => $event['end'] ?? null,
                'state' => $state,
                'reason' => $reason,
            ];
        }

        $states = array_column($observations, 'state');
        $aggregate = match (true) {
            in_array('LIVE', $states, true) => 'LIVE',
            $states !== [] && !in_array('UNKNOWN', $states, true) => 'STALE_CONFIRMED',
            default => 'UNKNOWN',
        };

        return ['state' => $aggregate, 'transactions' => $observations];
    }

    /** @param ObservedEvent $event */
    private static function ended(array $event): bool
    {
        return in_array($event['state'], ['ROLLED BACK', 'COMMITTED'], true)
            && $event['event'] !== null && $event['event'] !== '0'
            && $event['end'] !== null && $event['end'] !== '0';
    }

    /**
     * MySQL documents ENGINE_TRANSACTION_ID as an InnoDB ID for transactions
     * which have written. A read-only lock's value cannot identify a residue.
     *
     * @param Residue $row
     * @param ObservedEvent $event
     * @param list<Lock> $locks
     * @param list<Writer> $writers
     */
    private static function hasWriterLock(array $row, array $event, array $locks, array $writers): bool
    {
        $candidate = ['id' => $row['id'], 'process' => $row['thread'], 'started' => $row['started']];
        if ($row['id'] === '0' || !in_array($candidate, $writers, true)) {
            return false;
        }

        return in_array(['thread' => $event['thread'], 'transaction' => $row['id']], $locks, true);
    }

    /**
     * @param list<Residue> $transactions
     * @return Result
     */
    public static function unknown(array $transactions, string $reason): array
    {
        return [
            'state' => 'UNKNOWN',
            'transactions' => array_map(
                static fn(array $row): array => [
                    'id' => $row['id'],
                    'process' => $row['thread'],
                    'thread' => null,
                    'event' => null,
                    'eventState' => null,
                    'endEvent' => null,
                    'state' => 'UNKNOWN',
                    'reason' => $reason,
                ],
                $transactions,
            ),
        ];
    }

    private static function instrumented(PDO $observer): bool
    {
        $rows = self::rows($observer, "SELECT"
            . " (SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE ENABLED = 'YES'"
            . " AND NAME IN ('global_instrumentation','thread_instrumentation','events_transactions_current')) AS consumers,"
            . " (SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME = 'transaction' AND ENABLED = 'YES') AS instruments");

        return in_array($rows[0]['consumers'] ?? null, [3, '3'], true) && in_array($rows[0]['instruments'] ?? null, [1, '1'], true);
    }

    /** @return array<int, ObservedEvent> */
    private static function events(PDO $observer): array
    {
        $rows = self::rows($observer, 'SELECT t.PROCESSLIST_ID, t.THREAD_ID, t.INSTRUMENTED, e.EVENT_ID, e.STATE, e.END_EVENT_ID'
            . ' FROM performance_schema.threads t LEFT JOIN performance_schema.events_transactions_current e ON e.THREAD_ID = t.THREAD_ID'
            . " WHERE t.PROCESSLIST_ID IS NOT NULL AND t.PROCESSLIST_ID <> CONNECTION_ID() AND t.TYPE = 'FOREGROUND'");
        $events = [];
        foreach ($rows as $row) {
            $process = (int) self::id($row['PROCESSLIST_ID']);
            $state = $row['STATE'];
            if (isset($events[$process]) || !in_array($state, [null, 'ACTIVE', 'COMMITTED', 'ROLLED BACK'], true)) {
                throw new UnexpectedValueException('Unrecognized transaction event.');
            }
            $events[$process] = [
                'thread' => self::id($row['THREAD_ID']),
                'instrumented' => $row['INSTRUMENTED'] === 'YES',
                'event' => $row['EVENT_ID'] === null ? null : self::id($row['EVENT_ID']),
                'state' => $state,
                'end' => $row['END_EVENT_ID'] === null ? null : self::id($row['END_EVENT_ID']),
            ];
        }
        ksort($events);

        return $events;
    }

    /** @return list<Writer> */
    private static function writers(PDO $observer): array
    {
        $rows = self::rows($observer, 'SELECT trx_id, trx_mysql_thread_id, trx_started'
            . ' FROM information_schema.innodb_trx WHERE trx_rows_modified > 0');
        $writers = [];
        foreach ($rows as $row) {
            if (!is_string($row['trx_started'])) {
                throw new UnexpectedValueException('Unrecognized transaction start time.');
            }
            $writers[] = [
                'id' => self::id($row['trx_id']),
                'process' => (int) self::id($row['trx_mysql_thread_id']),
                'started' => $row['trx_started'],
            ];
        }

        return $writers;
    }

    /** @return list<Lock> */
    private static function locks(PDO $observer): array
    {
        $rows = self::rows($observer, "SELECT DISTINCT THREAD_ID, ENGINE_TRANSACTION_ID"
            . " FROM performance_schema.data_locks WHERE ENGINE = 'INNODB'");
        $locks = [];
        foreach ($rows as $row) {
            $locks[] = ['thread' => self::id($row['THREAD_ID']), 'transaction' => self::id($row['ENGINE_TRANSACTION_ID'])];
        }

        return $locks;
    }

    private static function id(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value) !== 1) {
            throw new UnexpectedValueException('Unrecognized server identifier.');
        }

        return (string) $value;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDO $observer, string $sql): array
    {
        $statement = $observer->query($sql);
        if ($statement === false) {
            throw new UnexpectedValueException('Diagnostic statement failed.');
        }
        try {
            $rows = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            if (!$statement->closeCursor()) {
                throw new UnexpectedValueException('Could not close diagnostic cursor.');
            }
        }
    }
}
