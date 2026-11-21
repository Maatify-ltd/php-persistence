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
if ($host === false || $port === false || !in_array($target, [
    'START TRANSACTION READ ONLY',
    'START TRANSACTION READ WRITE',
    'COMMIT AND NO CHAIN NO RELEASE',
    'ROLLBACK AND NO CHAIN NO RELEASE',
], true) || ! in_array($stage, ['after', 'before', 'after-ack'], true)) {
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
