<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function databaseWorkCommand;
use function execute;

require_once dirname(__DIR__, 2) . '/scripts/integration-support.php';

final class DatabaseWorkSupervisionTest extends TestCase
{
    public function testNormalWorkPreservesOutputAndExitStatus(): void
    {
        foreach ([0, 7] as $expected) {
            [$status, $out, $error] = execute(databaseWorkCommand([
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "worker output\n"); fwrite(STDERR, "worker error\n"); exit((int) $argv[1]);',
                (string) $expected,
            ], 5), timeoutSeconds: 8);

            self::assertSame($expected, $status, $out . $error);
            self::assertStringContainsString('worker output', $out);
            self::assertStringContainsString('worker error', $error);
            if ($expected === 0) {
                self::assertStringContainsString('workers=0', $out);
            }
        }
    }

    #[TestWith([false, 0])]
    #[TestWith([true, 0])]
    #[TestWith([true, 7])]
    public function testAnUnfinishedDescendantFailsBeforeBoundedCleanup(bool $attached, int $primary): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pp-db-worker-');
        self::assertIsString($path);
        try {
            $worker = 'pcntl_signal(SIGTERM, SIG_IGN); file_put_contents($argv[1], (string) getmypid()); sleep(15);';
            $launcher = <<<'SH'
                "$1" -r "$2" "$3" </dev/null >/dev/null 2>&1 &
                i=0
                while [ ! -s "$3" ] && [ "$i" -lt 200 ]; do
                    i=$((i+1))
                    sleep .01
                done
                test -s "$3" || exit 98
                exit "$4"
                SH;
            $command = ['/bin/sh', '-c', $launcher, 'fixture', PHP_BINARY, $worker, $path, (string) $primary];
            $start = hrtime(true);
            if ($attached) {
                $entry = 'require $argv[1]; exit(passthrough(array_slice($argv, 2), [], getcwd(), 5, databaseWork: true));';
                $command = [PHP_BINARY, '-r', $entry, dirname(__DIR__, 2) . '/scripts/integration-support.php', ...$command];
            } else {
                $command = databaseWorkCommand($command, 5);
            }
            [$status, $out, $error] = execute($command, timeoutSeconds: 8);

            self::assertSame($primary === 0 ? 125 : $primary, $status, $out . $error);
            self::assertStringContainsString('DB-WORK FAILURE: unfinished owned worker', $error);
            self::assertStringContainsString('DB-WORK cleanup verified', $error);
            self::assertStringNotContainsString('DB-WORK verified', $out);
            self::assertLessThan(5_000_000_000, hrtime(true) - $start);
            self::assertMatchesRegularExpression('/^\d+$/D', (string) file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testInterruptionPreservesFailureAndCleansOwnedWork(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pp-db-interrupt-');
        self::assertIsString($path);
        $launcher = <<<'PHP_CODE'
            require $argv[1];
            $cwd = getcwd();
            $script = 'file_put_contents($argv[1], "ready"); sleep(20);';
            $process = proc_open(databaseWorkCommand([PHP_BINARY, '-r', $script, $argv[2]], 8),
                [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
            if (!is_resource($process)) {
                exit(98);
            }
            $until = hrtime(true) + 3_000_000_000;
            do {
                clearstatcache();
                if (filesize($argv[2]) > 0) {
                    proc_terminate($process, SIGINT);
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $until);
            exit(proc_close($process));
            PHP_CODE;
        try {
            [$status, $out, $error] = execute([
                PHP_BINARY,
                '-r',
                $launcher,
                dirname(__DIR__, 2) . '/scripts/integration-support.php',
                $path,
            ], timeoutSeconds: 10);
            self::assertSame(130, $status, $out . $error);
            self::assertStringContainsString('DB-WORK FAILURE: interrupted', $error);
            self::assertStringContainsString('DB-WORK cleanup verified', $error);
            self::assertStringNotContainsString('DB-WORK verified', $out);
        } finally {
            unlink($path);
        }
    }

    public function testOverdueWorkFailsBeforeCleanup(): void
    {
        [$status, $out, $error] = execute(databaseWorkCommand([PHP_BINARY, '-r', 'sleep(60);'], 1), timeoutSeconds: 5);
        self::assertSame(124, $status, $out . $error);
        self::assertStringContainsString('DB-WORK FAILURE: timed out', $error);
        self::assertStringContainsString('DB-WORK cleanup verified', $error);
        self::assertStringNotContainsString('DB-WORK verified', $out);
    }
}
