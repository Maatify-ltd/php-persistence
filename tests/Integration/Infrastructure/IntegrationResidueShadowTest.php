<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Infrastructure;

use IntegrationResidueShadow;
use Maatify\PhpPersistence\Tests\Integration\IntegrationTestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use SensitiveParameter;

use function finishIntegrationResidueCheck;
use function readTransactionResidue;

require_once dirname(__DIR__, 3) . '/scripts/integration-support.php';

/**
 * @phpstan-import-type Residue from IntegrationResidueShadow
 * @phpstan-import-type Binding from IntegrationResidueShadow
 * @phpstan-import-type Result from IntegrationResidueShadow
 */
final class IntegrationResidueShadowTest extends IntegrationTestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testServerControlsKeepOriginalGate(bool $commit): void
    {
        $observer = $this->session('root', self::setting('ADMIN_PASSWORD'));
        $owner = $this->session(self::setting('USER'), self::setting('PASSWORD'));
        $reader = $this->session(self::setting('USER'), self::setting('PASSWORD'));
        $contender = $this->connect();
        $table = $this->createTable($contender, 'shadow');
        $contender->exec("INSERT INTO `$table` VALUES (1, 0)");
        $contender->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $process = (int) $this->scalar($owner, 'SELECT CONNECTION_ID()');
        $readerProcess = (int) $this->scalar($reader, 'SELECT CONNECTION_ID()');
        $instrumentation = self::engine() === 'mysql' ? $this->transactionEventSettings($observer) : null;

        try {
            if ($instrumentation !== null) {
                $observer->exec("UPDATE performance_schema.setup_instruments SET ENABLED='YES' WHERE NAME='transaction'");
                $observer->exec("UPDATE performance_schema.setup_consumers SET ENABLED='YES'"
                    . " WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_transactions_current')");
            }
            self::assertTrue($owner->beginTransaction());
            self::assertSame(1, $owner->exec("UPDATE `$table` SET v=1 WHERE id=1"));
            if ($instrumentation !== null) {
                $reader->exec('START TRANSACTION READ ONLY');
                self::assertSame('0', $this->scalar($reader, "SELECT v FROM `$table` WHERE id=1"));
                $this->expectLockFailure($contender, $table, ' NOWAIT', 3572);
            }

            // A real one-second lock wait also separates this observation from
            // any INNODB_TRX snapshot read by the preceding suite pass.
            $this->expectLockFailure($contender, $table, '', 1205);
            $rows = readTransactionResidue($observer);
            $row = $this->ownedRow($rows, $process);

            if ($instrumentation === null) {
                $shadow = IntegrationResidueShadow::observe($observer, self::engine(), [$row]);
                self::assertSame('ENGINE_PROFILE_UNQUALIFIED', $shadow['transactions'][0]['reason']);
                $this->assertOriginalGate($shadow, [$row], 'UNKNOWN');

                return;
            }

            $readerRow = $this->ownedRow($rows, $readerProcess);
            $writerBinding = $this->binding($observer, $row);
            $readerBinding = $this->binding($observer, $readerRow);
            self::assertSame('0', $this->scalar($observer, 'SELECT COUNT(*) FROM performance_schema.data_locks WHERE THREAD_ID=' . $readerBinding['thread']));

            $live = IntegrationResidueShadow::observe($observer, 'mysql', [$row, $readerRow]);
            self::assertSame(['LIVE', 'UNKNOWN'], array_column($live['transactions'], 'state'));
            $this->assertOriginalGate($live, [$row, $readerRow], 'LIVE');
            $readOnly = IntegrationResidueShadow::observe($observer, 'mysql', [$readerRow], [$readerProcess => $readerBinding]);
            $this->assertOriginalGate($readOnly, [$readerRow], 'LIVE');

            self::assertTrue($commit ? $owner->commit() : $owner->rollBack());
            $contender->beginTransaction();
            try {
                self::assertSame($commit ? '1' : '0', $this->scalar($contender, "SELECT v FROM `$table` WHERE id=1 FOR UPDATE NOWAIT"));
            } finally {
                $contender->rollBack();
            }
            $writerBinding['endAcknowledged'] = true;
            $writerBinding['independentlyChecked'] = true;
            $bindings = [$process => $writerBinding];
            $otherActive = IntegrationResidueShadow::observe($observer, 'mysql', [$row], $bindings);
            self::assertSame('UNKNOWN', $otherActive['state']);
            self::assertTrue($reader->rollBack());

            // Preserve the real pre-end snapshot to exercise the stale
            // classification even if the server cache refreshes immediately.
            // The historical residue decision must still consume that row.
            $stale = IntegrationResidueShadow::observe($observer, 'mysql', [$row], $bindings);
            $this->assertOriginalGate($stale, [$row], 'STALE_CONFIRMED');
            $unbound = IntegrationResidueShadow::observe($observer, 'mysql', [$row]);
            $this->assertOriginalGate($unbound, [$row], 'UNKNOWN');

            $limited = IntegrationResidueShadow::observe($contender, 'mysql', [$row], $bindings);
            self::assertSame('OBSERVATION_UNAVAILABLE', $limited['transactions'][0]['reason']);
            $this->assertOriginalGate($limited, [$row], 'UNKNOWN');

            self::assertTrue($owner->beginTransaction());
            self::assertSame($commit ? '1' : '0', $this->scalar($owner, "SELECT v FROM `$table` WHERE id=1"));
            $reused = IntegrationResidueShadow::observe($observer, 'mysql', [$row], $bindings);
            self::assertSame('BOUND_EVENT_CHANGED', $reused['transactions'][0]['reason']);
            $this->assertOriginalGate($reused, [$row], 'UNKNOWN');
            self::assertTrue($owner->rollBack());
            $owner = null;
            $disconnected = IntegrationResidueShadow::observe($observer, 'mysql', [$row], $bindings);
            $this->assertOriginalGate($disconnected, [$row], 'UNKNOWN');

            $observer->exec("UPDATE performance_schema.setup_consumers SET ENABLED='NO' WHERE NAME='events_transactions_current'");
            $disabled = IntegrationResidueShadow::observe($observer, 'mysql', [$row], $bindings);
            self::assertSame('TRANSACTION_INSTRUMENTATION_UNAVAILABLE', $disabled['transactions'][0]['reason']);
        } finally {
            foreach ([$owner, $reader] as $session) {
                if ($session?->inTransaction()) {
                    $session->rollBack();
                }
            }
            if ($instrumentation !== null) {
                $this->restoreTransactionEvents($observer, $instrumentation);
            }
        }
    }

    private function expectLockFailure(PDO $contender, string $table, string $suffix, int $expected): void
    {
        $contender->beginTransaction();
        try {
            $failure = self::failureOf(fn() => $this->scalar($contender, "SELECT v FROM `$table` WHERE id=1 FOR UPDATE" . $suffix));
            self::assertSame($expected, self::driverCode($failure));
        } finally {
            $contender->rollBack();
        }
    }

    /**
     * @param list<Residue> $rows
     * @return Residue
     */
    private function ownedRow(array $rows, int $process): array
    {
        foreach ($rows as $row) {
            if ($row['thread'] === $process) {
                return $row;
            }
        }
        self::fail('Missing controlled participant in the InnoDB observation.');
    }

    /**
     * @param Residue $row
     * @return Binding
     */
    private function binding(PDO $observer, array $row): array
    {
        $thread = $this->scalar($observer, 'SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=' . $row['thread']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $thread);
        $event = $this->scalar($observer, "SELECT EVENT_ID FROM performance_schema.events_transactions_current WHERE STATE='ACTIVE' AND THREAD_ID=" . $thread);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $event);

        // Only the fixture controls this session and its single active event.
        return ['transaction' => $row['id'], 'started' => $row['started'], 'thread' => $thread, 'event' => $event,
            'endAcknowledged' => false, 'independentlyChecked' => false];
    }

    /** @return array{instrument: string, consumers: array<string, string>} */
    private function transactionEventSettings(PDO $observer): array
    {
        $instrument = $this->scalar($observer, "SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME='transaction'");
        self::assertContains($instrument, ['YES', 'NO']);
        $consumers = [];
        foreach (['global_instrumentation', 'thread_instrumentation', 'events_transactions_current'] as $consumer) {
            $value = $this->scalar($observer, "SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME='$consumer'");
            self::assertContains($value, ['YES', 'NO']);
            $consumers[$consumer] = $value;
        }
        return ['instrument' => $instrument, 'consumers' => $consumers];
    }

    /** @param array{instrument: string, consumers: array<string, string>} $settings */
    private function restoreTransactionEvents(PDO $observer, array $settings): void
    {
        $observer->exec("UPDATE performance_schema.setup_instruments SET ENABLED='" . $settings['instrument'] . "' WHERE NAME='transaction'");
        foreach ($settings['consumers'] as $consumer => $value) {
            $observer->exec("UPDATE performance_schema.setup_consumers SET ENABLED='$value' WHERE NAME='$consumer'");
        }
    }

    /**
     * @param Result $shadow
     * @param list<Residue> $rows
     */
    private function assertOriginalGate(array $shadow, array $rows, string $expected): void
    {
        $lines = [];
        $status = finishIntegrationResidueCheck(
            1,
            0,
            $rows,
            static fn(): array => $shadow,
            static function (string $line, bool $error) use (&$lines): void {
                $lines[] = $line;
            },
        );
        self::assertSame($expected, $shadow['state']);
        self::assertSame(1, $status);
        self::assertSame('RESIDUE-CHECK pass=1 tables=0 openTransactions=' . count($rows), $lines[0]);
        self::assertStringContainsString('"state":"' . $expected . '"', $lines[1]);
    }

    private function session(string $user, #[SensitiveParameter] string $password): PDO
    {
        return new PDO(
            'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE'),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
