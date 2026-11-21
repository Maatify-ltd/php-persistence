<?php

declare(strict_types=1);

/**
 * Test-only packet relay. It withholds a successful response to one exact SQL
 * control command, after the real server has processed the complete command.
 * The fixture uses local, non-TLS pdo_mysql; credentials are never printed.
 */

/** @param resource $socket */
function readPacketBytes($socket, int $length): string
{
    $bytes = '';
    $remaining = $length;
    while ($remaining > 0) {
        $chunk = fread($socket, $remaining);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('The test relay lost a connection.');
        }
        $bytes .= $chunk;
        $remaining -= strlen($chunk);
    }

    return $bytes;
}

/** @param resource $socket */
function writePacketBytes($socket, string $bytes): void
{
    while ($bytes !== '') {
        $written = fwrite($socket, $bytes);
        if ($written === false || $written === 0) {
            throw new RuntimeException('The test relay could not forward a packet.');
        }
        $bytes = substr($bytes, $written);
    }
}

$host = getenv('PHP_PERSISTENCE_IT_HOST');
$port = getenv('PHP_PERSISTENCE_IT_PORT');
$target = $argv[1] ?? '';
$stage = $argv[2] ?? '';
$fixtureTable = $argv[3] ?? '';
if ($host === false || $port === false || !in_array($target, [
    'START TRANSACTION READ ONLY',
    'START TRANSACTION READ WRITE',
    'COMMIT AND NO CHAIN NO RELEASE',
    'ROLLBACK AND NO CHAIN NO RELEASE',
    'RELEASE SAVEPOINT',
], true) || !in_array($stage, ['after', 'before', 'after-ack', 'ordering-release-error', 'ordering-1020', 'protected-tail-churn'], true)
    || ($stage === 'protected-tail-churn' && preg_match('/\Ait_ordering_scoped_[0-9a-f]{12}\z/', $fixtureTable) !== 1)
) {
    throw new InvalidArgumentException('Missing relay target.');
}
$listener = stream_socket_server('tcp://127.0.0.1:0');
if ($listener === false) {
    throw new RuntimeException('Could not start the test relay.');
}
try {
    $name = stream_socket_get_name($listener, false);
    echo "READY {$name}\n";
    fflush(STDOUT);
    $client = stream_socket_accept($listener, 10);
    if ($client === false) {
        throw new RuntimeException('No PDO connection reached the test relay.');
    }
    try {
        $server = stream_socket_client('tcp://' . $host . ':' . $port, timeout: 10);
        if ($server === false) {
            throw new RuntimeException('The relay could not reach the database.');
        }
        try {
            stream_set_timeout($client, 10);
            stream_set_timeout($server, 10);
            $awaitingTargetResponse = false;
            $savepointSent = false;
            $orderingErrorSent = false;
            $tailReads = 0;
            for (;;) {
                $readable = [$client, $server];
                $write = $except = null;
                $ready = stream_select($readable, $write, $except, 10);
                if ($ready === false || $ready === 0) {
                    throw new RuntimeException('The relay did not receive a ready peer.');
                }
                $peer = reset($readable);
                $header = readPacketBytes($peer, 4);
                $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
                $sequence = ord($header[3]);
                $payload = readPacketBytes($peer, $length);
                if ($stage === 'ordering-1020' && $peer === $client && $sequence === 0) {
                    if (preg_match('/\A\x03SAVEPOINT maatify_persistence_sp_[0-9a-f]{32}\z/', $payload) === 1) {
                        $savepointSent = true;
                    } elseif ($savepointSent && preg_match('/\A\x03UPDATE .*?WHERE `id` = /s', $payload) === 1) {
                        $payload = "\x03SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO = 1020, MESSAGE_TEXT = 'fixture movement failure'";
                        $header = substr(pack('V', strlen($payload)), 0, 3) . $header[3];
                        $orderingErrorSent = true;
                    }
                }
                if ($orderingErrorSent && $peer === $server) {
                    if (ord($payload[0]) !== 0xff || unpack('v', substr($payload, 1, 2)) !== [1 => 1020]) {
                        throw new RuntimeException('The server did not respond with the expected injected error.');
                    }
                    writePacketBytes($client, $header . $payload);
                    $afterError = [$client];
                    if (stream_select($afterError, $write, $except, 0, 500_000) !== 0) {
                        throw new RuntimeException('A packet followed the fenced driver error.');
                    }
                    echo "NO SQL AFTER DRIVER ERROR\n";
                    fflush(STDOUT);
                    break;
                }
                if ($stage === 'protected-tail-churn' && $peer === $client && $sequence === 0
                    && str_starts_with($payload, "\x03SELECT `display_order`")
                    && str_contains($payload, 'FROM `' . $fixtureTable . '`')
                    && str_contains($payload, 'ORDER BY `display_order` DESC, `id` DESC')
                    && str_ends_with($payload, 'FOR UPDATE')
                    && ++$tailReads === 2
                ) {
                    $writer = new PDO(
                        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . getenv('PHP_PERSISTENCE_IT_DATABASE'),
                        (string) getenv('PHP_PERSISTENCE_IT_USER'),
                        (string) getenv('PHP_PERSISTENCE_IT_PASSWORD'),
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4],
                    );
                    $writer->exec('SET SESSION innodb_lock_wait_timeout = 4');
                    $writer->exec("INSERT INTO `$fixtureTable` (scope_key, display_order, label) VALUES ('target', 3, 'new-tail')");
                    echo "TAIL ADVANCED\n";
                    fflush(STDOUT);
                }
                if ($stage === 'ordering-release-error' && $peer === $client && $sequence === 0) {
                    if (preg_match('/\A\x03UPDATE .*?WHERE `id` = /s', $payload) === 1) {
                        $payload .= ' AND 1 = 0';
                        echo "TARGET UPDATE MADE EMPTY\n";
                        fflush(STDOUT);
                    } elseif (preg_match('/\A\x03RELEASE SAVEPOINT maatify_persistence_sp_[0-9a-f]{32}\z/', $payload) === 1) {
                        $payload = "\x03RELEASE SAVEPOINT fixture_savepoint_that_does_not_exist";
                        echo "RELEASE REPLACED AFTER UNDO\n";
                        fflush(STDOUT);
                    }
                    $header = substr(pack('V', strlen($payload)), 0, 3) . $header[3];
                }
                if ($peer === $client && $sequence === 0 && $payload === "\x03" . $target) {
                    if ($stage === 'before') {
                        echo "COMMAND NOT FORWARDED\n";
                        fflush(STDOUT);
                        break;
                    }
                    $awaitingTargetResponse = true;
                }
                if ($peer === $server && $awaitingTargetResponse) {
                    if ($sequence !== 1 || !str_starts_with($payload, "\x00")) {
                        throw new RuntimeException('The real server did not acknowledge the target command.');
                    }
                    if ($stage === 'after-ack') {
                        writePacketBytes($client, $header . $payload);
                    }
                    echo $stage === 'after-ack' ? "SERVER OK FORWARDED\n" : "SERVER OK WITHHELD\n";
                    fflush(STDOUT);
                    break;
                }
                writePacketBytes($peer === $client ? $server : $client, $header . $payload);
            }
        } finally {
            fclose($server);
        }
    } finally {
        fclose($client);
    }
} finally {
    fclose($listener);
}
