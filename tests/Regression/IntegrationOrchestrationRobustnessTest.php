<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Failure-path protection of scripts/integration.php that needs no Docker: a stub `docker`
 * executable stands in for the CLI. Every child process runs under a deadline so that a
 * regression shows up as a failure instead of a hung suite.
 */
final class IntegrationOrchestrationRobustnessTest extends TestCase
{
    private const DEADLINE_SECONDS = 60;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     *
     * @return array{int, string, string} status (-1 when the deadline expired), standard output, standard error
     */
    private static function runWithDeadline(array $command, array $environment = []): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment === [] ? null : [...getenv(), ...$environment],
        );
        self::assertIsResource($process);

        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }
        $deadline = time() + self::DEADLINE_SECONDS;
        while ($open !== []) {
            if (time() >= $deadline) {
                proc_terminate($process, 9);
                foreach ($open as $stream) {
                    fclose($stream);
                }
                proc_close($process);

                return [-1, $buffers[1], $buffers[2]];
            }
            $read = array_values($open);
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 1);
            foreach ($open as $index => $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $buffers[$index] .= $chunk;
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$index]);
                }
            }
        }

        return [proc_close($process), $buffers[1], $buffers[2]];
    }

    public function testExecuteDrainsLargeOutputOnBothStreamsWithoutBlocking(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $child = 'require ' . var_export($support, true) . ';'
            . '[$status, $out, $err] = execute([PHP_BINARY, "-r", "fwrite(STDERR, str_repeat(\"e\", 262144)); fwrite(STDOUT, str_repeat(\"o\", 262144)); exit(7);"]);'
            . 'echo json_encode([$status, strlen($out), strlen($err)]);';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child]);

        self::assertNotSame(-1, $status, 'execute() hung on a full pipe');
        self::assertSame(0, $status, $err);
        self::assertSame('[7,262144,262144]', $out);
    }

    public function testExecuteReportsAMissingBinary(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $child = 'require ' . var_export($support, true) . ';echo json_encode(execute(["/nonexistent/binary"]));';

        [$status, $out] = self::runWithDeadline([PHP_BINARY, '-r', $child]);

        self::assertSame(0, $status);
        self::assertStringContainsString('127', $out);
    }

    public function testDockerFailureWithHugeStderrStillTearsDownAndFailsClosed(): void
    {
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $log = $directory . '/calls.log';
        $stub = $directory . '/docker';
        $this->cleanup = [$stub, $log, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            echo "$*" >> "$(dirname "$0")/calls.log"
            case " $* " in
              *" up "*)
                yes 'simulated docker failure line' | head -c 262144 >&2
                exit 1
                ;;
              *" logs "*) echo "simulated log"; exit 0 ;;
            esac
            exit 0
            SH);
        chmod($stub, 0755);

        [$status, $out, $err] = self::runWithDeadline(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/integration.php', '--cell=mysql-8.4.13'],
            ['PATH' => $directory . ':/usr/bin:/bin'],
        );

        self::assertNotSame(-1, $status, 'the orchestration hung on a failing Docker command');
        self::assertSame(3, $status, $out . $err);
        self::assertStringContainsString('Service did not become healthy', $err);
        self::assertStringContainsString('TEARDOWN VERIFIED', $out);

        $calls = file_get_contents($log);
        self::assertIsString($calls);
        self::assertMatchesRegularExpression('/compose .* up --detach --wait/', $calls);
        self::assertMatchesRegularExpression('/compose .* down --volumes --remove-orphans/', $calls, 'teardown ran after the failure');
    }

    #[TestWith(['INT', 'down', false])]
    #[TestWith(['TERM', 'volume', false])]
    #[TestWith(['TERM', 'volume', true])]
    public function testExamplesTeardownCompletesVerificationAfterInterruption(string $signal, string $phase, bool $remainingVolume): void
    {
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $stub = $directory . '/docker';
        $log = $directory . '/calls.log';
        $this->cleanup = [$stub, $log, $directory . '/interrupted', $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            stub_dir=$(dirname "$0")
            case " $* " in
              *" up "*) echo 'test startup failure' >&2; exit 1 ;;
              *" down "*) phase=down ;;
              *" ps -aq "*) phase=ps ;;
              *" volume ls -q "*) phase=volume ;;
              *" network ls -q "*) phase=network ;;
              *) exit 0 ;;
            esac
            echo "$phase" >> "$stub_dir/calls.log"
            if [ "$phase" = "$PP_TEST_INTERRUPT_PHASE" ] && [ ! -f "$stub_dir/interrupted" ]; then
                touch "$stub_dir/interrupted"
                sleep 0.1
                kill "-$PP_TEST_INTERRUPT_SIGNAL" "$PPID"
                exec sleep 10
            fi
            if [ "$phase" = volume ] && [ "$PP_TEST_REMAINING_VOLUME" = yes ]; then
                echo 'leftover-volume'
            fi
            exit 0
            SH);
        chmod($stub, 0755);

        $environment = [
            'PATH' => $directory . ':/usr/bin:/bin',
            'PP_TEARDOWN_TIMEOUT_SECONDS' => '10',
            'PP_TEST_INTERRUPT_PHASE' => $phase,
            'PP_TEST_INTERRUPT_SIGNAL' => $signal,
            'PP_TEST_REMAINING_VOLUME' => $remainingVolume ? 'yes' : 'no',
        ];
        [$status, $out, $err] = self::runWithDeadline(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/examples-smoke.php', '--cell=mysql-8.4.13'],
            $environment,
        );

        self::assertSame(3, $status, $out . $err);
        self::assertStringContainsString('test startup failure', $err);
        self::assertStringContainsString('EXAMPLES SMOKE FAILED cell=mysql-8.4.13 status=3', $out);
        self::assertStringNotContainsString('Uncaught', $err);

        $calls = file_get_contents($log);
        self::assertIsString($calls);
        self::assertSame($phase === 'down' ? "down\ndown\nps\nvolume\nnetwork\n" : "down\nps\nvolume\ndown\nps\nvolume\nnetwork\n", $calls);
        if ($remainingVolume) {
            self::assertStringContainsString('Teardown incomplete', $err);
            self::assertStringContainsString('volume ls -q', $err);
            self::assertStringNotContainsString('TEARDOWN VERIFIED', $out);
        } else {
            self::assertSame(1, substr_count($out, 'TEARDOWN VERIFIED'));
        }
    }

    public function testTeardownDoesNotStartNewCommandsWhenTheOriginalDeadlineExpired(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $child = 'require ' . var_export($support, true) . ';'
            . 'echo json_encode(teardown(["/bin/sh", "-c", "echo should-not-run"], "smoke-test", [], "/tmp", microtime(true) - 1));';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child]);

        self::assertSame(0, $status, $err);
        self::assertSame('false', $out);
        self::assertStringContainsString('teardown time exhausted', $err);
    }

    public function testTeardownResidueTurnsAnOtherwiseSuccessfulRunIntoAnInfrastructureFailure(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $stub = $directory . '/docker';
        $this->cleanup = [$stub, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            case " $* " in
              *" ps "*) echo leftover-container ;;
            esac
            exit 0
            SH);
        chmod($stub, 0755);
        $child = 'require ' . var_export($support, true) . ';'
            . 'echo json_encode(teardown(["docker", "compose"], "phppersist-it-test", [], "/tmp"));';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child], ['PATH' => $directory . ':/usr/bin:/bin']);

        self::assertSame(0, $status, $err);
        self::assertSame('false', $out);
        self::assertStringContainsString('Teardown incomplete', $err);
    }

    public function testExecuteStopsAHungCommandAtItsDeadline(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $child = 'require ' . var_export($support, true) . ';$start = microtime(true);'
            . '[$status, , $err] = execute([PHP_BINARY, "-r", "sleep(60);"], [], null, 2);'
            . 'echo json_encode([$status, (int) round(microtime(true) - $start), str_contains($err, "timed out after 2 seconds")]);';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child]);

        self::assertSame(0, $status, $err);
        self::assertSame('[124,2,true]', $out);
    }

    public function testExecuteKillsACommandThatIgnoresTheTerminationSignal(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $child = 'require ' . var_export($support, true) . ';$start = microtime(true);'
            . '[$status] = execute(["sh", "-c", "trap \"\" TERM; while :; do :; done"], [], null, 1);'
            . '$elapsed = microtime(true) - $start;'
            . 'echo json_encode([$status, $elapsed >= 5 && $elapsed < 20]);';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child]);

        self::assertSame(0, $status, $err);
        self::assertSame('[124,true]', $out, 'SIGTERM is ignored, so the process must be killed after the grace period');
    }

    public function testPassthroughStopsAHungSuiteAtItsDeadline(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $child = 'require ' . var_export($support, true) . ';'
            . 'echo json_encode(passthrough([PHP_BINARY, "-r", "sleep(60);"], [], "/tmp", 2));';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child]);

        self::assertSame(0, $status, $err);
        self::assertStringEndsWith('124', $out);
        self::assertStringContainsString('timed out after 2 seconds', $err);
    }

    public function testHungDockerStartStillTearsDownAndFailsClosed(): void
    {
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $log = $directory . '/calls.log';
        $stub = $directory . '/docker';
        $this->cleanup = [$stub, $log, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            echo "$*" >> "$(dirname "$0")/calls.log"
            case " $* " in
              *" up "*) exec sleep 120 ;;
            esac
            exit 0
            SH);
        chmod($stub, 0755);

        [$status, $out, $err] = self::runWithDeadline(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/integration.php', '--cell=mysql-8.4.13'],
            ['PATH' => $directory . ':/usr/bin:/bin', 'PP_UP_TIMEOUT_SECONDS' => '2'],
        );

        self::assertNotSame(-1, $status, 'the orchestration hung on a docker command that never returns');
        self::assertSame(3, $status, $out . $err);
        self::assertStringContainsString('timed out after 2 seconds', $err);
        self::assertStringContainsString('TEARDOWN VERIFIED', $out);
        $calls = file_get_contents($log);
        self::assertIsString($calls);
        self::assertMatchesRegularExpression('/compose .* down --volumes --remove-orphans/', $calls, 'teardown ran after the timeout');
    }

    public function testAnInvalidTimeoutSettingIsAConfigurationError(): void
    {
        [$status, , $err] = self::runWithDeadline(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/integration.php', '--cell=mysql-8.4.13'],
            ['PP_UP_TIMEOUT_SECONDS' => 'soon', 'PATH' => '/usr/bin:/bin'],
        );

        self::assertSame(2, $status, $err);
        self::assertStringContainsString('PP_UP_TIMEOUT_SECONDS must be a whole number of seconds', $err);
    }

    public function testFailedComposeDownIsNeverReportedAsVerifiedEvenWhenNoResourceRemains(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $stub = $directory . '/docker';
        $this->cleanup = [$stub, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            case " $* " in
              *" down "*) echo "daemon unavailable" >&2; exit 1 ;;
            esac
            exit 0
            SH);
        chmod($stub, 0755);
        $child = 'require ' . var_export($support, true) . ';'
            . 'echo json_encode(teardown(["docker", "compose"], "phppersist-it-test", [], "/tmp"));';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child], ['PATH' => $directory . ':/usr/bin:/bin']);

        self::assertSame(0, $status, $err);
        self::assertSame('false', $out, 'the resource queries are empty, but the failed down must still fail the teardown');
        self::assertStringContainsString('docker compose down failed with status 1', $err);
        self::assertStringContainsString('daemon unavailable', $err);
    }

    public function testTeardownRetriesComposeDownOnceAfterATransientFailure(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $stub = $directory . '/docker';
        $marker = $directory . '/failed-once';
        $this->cleanup = [$stub, $marker, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            case " $* " in
              *" down "*)
                if [ ! -f "$(dirname "$0")/failed-once" ]; then touch "$(dirname "$0")/failed-once"; exit 1; fi
                ;;
            esac
            exit 0
            SH);
        chmod($stub, 0755);
        $child = 'require ' . var_export($support, true) . ';'
            . 'echo json_encode(teardown(["docker", "compose"], "phppersist-it-test", [], "/tmp"));';

        [$status, $out, $err] = self::runWithDeadline([PHP_BINARY, '-r', $child], ['PATH' => $directory . ':/usr/bin:/bin']);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('TEARDOWN VERIFIED', $out);
        self::assertStringEndsWith('true', $out);
    }

    public function testTheOverallBudgetStopsWorkEarlyEnoughToLeaveTimeForTeardown(): void
    {
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $log = $directory . '/calls.log';
        $stub = $directory . '/docker';
        $this->cleanup = [$stub, $log, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            echo "$*" >> "$(dirname "$0")/calls.log"
            case " $* " in
              *" up "*) exec sleep 120 ;;
            esac
            exit 0
            SH);
        chmod($stub, 0755);

        $start = microtime(true);
        $environment = [
            'PATH' => $directory . ':/usr/bin:/bin',
            'PP_TEARDOWN_TIMEOUT_SECONDS' => '5',
            'PP_TOTAL_BUDGET_SECONDS' => '38',
        ];
        [$status, $out, $err] = self::runWithDeadline(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/integration.php', '--cell=mysql-8.4.13'],
            $environment,
        );

        self::assertSame(3, $status, $out . $err);
        self::assertMatchesRegularExpression('/timed out after [1-3] seconds/', $err, 'startup was limited by the budget (38 s minus the 35 s reserve), not by its own 660 s');
        self::assertStringContainsString('TEARDOWN VERIFIED', $out);
        self::assertLessThan(30, microtime(true) - $start);
        $calls = file_get_contents($log);
        self::assertIsString($calls);
        self::assertMatchesRegularExpression('/compose .* down --volumes --remove-orphans/', $calls);
    }

    public function testABudgetThatCannotCoverTheTeardownReserveIsRejectedUpFront(): void
    {
        [$status, , $err] = self::runWithDeadline(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/integration.php', '--cell=mysql-8.4.13'],
            ['PP_TOTAL_BUDGET_SECONDS' => '10', 'PATH' => '/usr/bin:/bin'],
        );

        self::assertSame(2, $status, $err);
        self::assertStringContainsString('must exceed the teardown reserve of 210 seconds', $err);
    }

    public function testASlowFailingDockerCannotStretchTheTeardownBeyondItsSingleDeadline(): void
    {
        $support = dirname(__DIR__, 2) . '/scripts/integration-support.php';
        $directory = sys_get_temp_dir() . '/ppstub' . bin2hex(random_bytes(4));
        mkdir($directory);
        $stub = $directory . '/docker';
        $this->cleanup = [$stub, $directory];
        file_put_contents($stub, <<<'SH'
            #!/bin/sh
            exec sleep 120
            SH);
        chmod($stub, 0755);
        $child = 'require ' . var_export($support, true) . ';$start = microtime(true);'
            . '$result = teardown(["docker", "compose"], "phppersist-it-test", [], "/tmp");'
            . 'echo json_encode([$result, microtime(true) - $start]);';

        [$status, $out, $err] = self::runWithDeadline(
            [PHP_BINARY, '-r', $child],
            ['PATH' => $directory . ':/usr/bin:/bin', 'PP_TEARDOWN_TIMEOUT_SECONDS' => '6'],
        );

        self::assertSame(0, $status, $err);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertFalse($decoded[0], 'nothing could be verified, so the teardown must fail');
        // One deadline of 6 s plus at most one 5 s termination grace. Separate per-command deadlines would take 30 s.
        self::assertGreaterThanOrEqual(5.0, $decoded[1]);
        self::assertLessThan(14.0, $decoded[1], 'the teardown commands share one deadline');
        self::assertStringContainsString('docker compose down failed with status 124', $err);
        self::assertStringContainsString('ps -aq not checked (teardown time exhausted)', $err);
        self::assertStringContainsString('network ls -q not checked (teardown time exhausted)', $err);
    }
}
