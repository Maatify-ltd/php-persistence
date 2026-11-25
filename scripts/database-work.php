<?php

declare(strict_types=1);

require_once __DIR__ . '/integration-support.php';

const DATABASE_WORK_FAILURE = 125;

/** @phpstan-impure A signal can arrive between checks. */
function databaseWorkInterrupted(): bool
{
    return pcntl_signal_get_handler(SIGTERM) === SIG_IGN;
}

/**
 * Own the direct worker in a new group, then move out of that group and keep
 * the group ID reserved until the supervisor has observed or contained it.
 *
 * @param resource $control
 * @param list<string> $command
 */
function runDatabaseWorker($control, array $command, int $supervisor): never
{
    if (!posix_setpgid(0, 0)) {
        exit(DATABASE_WORK_FAILURE);
    }

    // No credential-bearing command starts until the parent has observed the
    // new group. If it cancels startup, closing the socket releases this child.
    stream_set_timeout($control, 5);
    if (fwrite($control, "R\n") !== 2 || fread($control, 1) !== 'G') {
        exit(DATABASE_WORK_FAILURE);
    }

    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        exit(DATABASE_WORK_FAILURE);
    }
    do {
        $status = proc_get_status($process);
        if ($status['running']) {
            usleep(10_000);
        }
    } while ($status['running']);

    $closed = proc_close($process);
    $result = $status['signaled'] ? 128 + $status['termsig'] : ($closed === -1 ? $status['exitcode'] : $closed);

    if (!posix_setpgid(0, $supervisor)) {
        exit(DATABASE_WORK_FAILURE);
    }
    fwrite($control, $result . "\n");
    // The parent releases this child only after it has finished using the PGID.
    fread($control, 1);
    exit(0);
}

/**
 * false is a positive absence (ESRCH). Other errors do not certify completion.
 *
 * @phpstan-impure
 */
function databaseWorkGroupExists(int $group): ?bool
{
    if (posix_kill(-$group, 0)) {
        return true;
    }

    return posix_get_last_error() === 3 ? false : null; // ESRCH on Linux and macOS
}

/**
 * @param resource $control
 */
function finishDatabaseWork($control, int $group, int $failure): int
{
    if ($failure !== 0 && databaseWorkGroupExists($group) !== false) {
        posix_kill(-$group, SIGTERM);
        $until = hrtime(true) + 350_000_000;
        while (hrtime(true) < $until && databaseWorkGroupExists($group) !== false) {
            usleep(10_000);
        }
        if (databaseWorkGroupExists($group) !== false) {
            posix_kill(-$group, SIGKILL);
        }
    }

    // Do not reap the group owner before the final signal to that group. Its
    // PID cannot be recycled until this parent reaps it, including if it died.
    fclose($control);
    $until = hrtime(true) + 600_000_000;
    $reaped = false;
    $status = 0;
    do {
        // After the last group signal it is safe to reap the controller even
        // when the kernel still counted it as a zombie in its former group.
        if (!$reaped) {
            $reaped = pcntl_waitpid($group, $status, WNOHANG) === $group;
        }
        if ($reaped && databaseWorkGroupExists($group) === false) {
            if ($failure === 0 && (!is_int($status) || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0)) {
                fwrite(STDERR, "DB-WORK controller did not exit normally.\n");

                return DATABASE_WORK_FAILURE;
            }
            if ($failure !== 0) {
                fwrite(STDERR, "DB-WORK cleanup verified group=$group workers=0\n");
            }

            return $failure;
        }
        usleep(10_000);
    } while (hrtime(true) < $until);

    fwrite(STDERR, "DB-WORK cleanup unverified group=$group original status=$failure\n");

    return $failure === 0 ? DATABASE_WORK_FAILURE : $failure;
}

/**
 * @param list<string> $command
 */
function superviseDatabaseWork(array $command, int $timeoutSeconds): int
{
    if (!function_exists('posix_setsid') || !function_exists('posix_setpgid') || !function_exists('posix_kill')
        || !function_exists('pcntl_async_signals') || !function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')
        || !function_exists('pcntl_signal_get_handler')) {
        fwrite(STDERR, "DB-WORK requires PHP CLI pcntl/posix for process ownership.\n");

        return DATABASE_WORK_FAILURE;
    }
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, static function (): void {
            pcntl_signal(SIGINT, SIG_IGN);
            pcntl_signal(SIGTERM, SIG_IGN);
        });
    }
    $supervisor = posix_setsid();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($supervisor === -1 || $pair === false || databaseWorkInterrupted()) {
        fwrite(STDERR, "DB-WORK could not start an owned session.\n");

        return DATABASE_WORK_FAILURE;
    }
    [$control, $worker] = $pair;
    $group = pcntl_fork();
    if ($group === -1) {
        fwrite(STDERR, "DB-WORK could not allocate an owned group.\n");

        return DATABASE_WORK_FAILURE;
    }
    if ($group === 0) {
        fclose($control);
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);
        runDatabaseWorker($worker, $command, $supervisor);
    }
    fclose($worker);
    stream_set_blocking($control, false);
    $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
    $reply = '';
    $started = false;
    while (!databaseWorkInterrupted() && hrtime(true) < $deadline && !feof($control)) {
        $reply .= (string) fgets($control, 16);
        if ($reply === "R\n" && !$started) {
            if (databaseWorkGroupExists($group) !== true || fwrite($control, 'G') !== 1) {
                break;
            }
            $started = true;
            $reply = '';
        } elseif (strlen($reply) >= 16 || str_ends_with($reply, "\n")) {
            break;
        }
        usleep(10_000);
    }

    $members = databaseWorkGroupExists($group);
    $result = $started && preg_match('/\A(\d{1,3})\n\z/', $reply, $match) === 1 ? (int) $match[1] : null;
    if (!databaseWorkInterrupted() && $result !== null && hrtime(true) < $deadline && $members === false) {
        $status = finishDatabaseWork($control, $group, $result);
        if ($status === 0) {
            if (databaseWorkInterrupted()) {
                fwrite(STDERR, "DB-WORK FAILURE: interrupted after reap, status=130 group=$group\n");

                return 130;
            }
            fwrite(STDOUT, "DB-WORK verified group=$group workers=0\n");
        }

        return $status;
    }

    // Record failure before any emergency cleanup. A zero after cleanup
    // cannot replace an unfinished or unobservable group at the boundary.
    $failure = $result !== null && $result !== 0 ? $result : DATABASE_WORK_FAILURE;
    $reason = $members === true && $result !== null ? 'unfinished owned worker' : 'ownership observation failed';
    if (databaseWorkInterrupted()) {
        $failure = 130;
        $reason = 'interrupted';
    } elseif (hrtime(true) >= $deadline) {
        $failure = TIMEOUT_EXIT_STATUS;
        $reason = 'timed out';
    }
    fwrite(STDERR, "DB-WORK FAILURE: $reason, status=$failure group=$group\n");

    return finishDatabaseWork($control, $group, $failure);
}

$arguments = $argv ?? [];
$timeoutSeconds = (int) ($arguments[1] ?? 0);
$command = array_slice($arguments, 2);
if ($command === [] || $timeoutSeconds < 1) {
    exit(DATABASE_WORK_FAILURE);
}
exit(superviseDatabaseWork($command, $timeoutSeconds));
