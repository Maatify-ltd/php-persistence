<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Support;

use PDOStatement;
use RuntimeException;

/** Reports real locking reads without changing any SQL or returned values. */
final class ObservedTailStatement extends PDOStatement
{
    private int $reads = 0;
    private float $executeMs = 0.0;
    private string $tail = 'EOF';

    protected function __construct() {}

    /** @param array<mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        if (++$this->reads > 8) {
            throw new RuntimeException('The bounded tail-read fixture exceeded eight statements.');
        }

        $startedAt = hrtime(true);
        $executed = parent::execute($params);
        $this->executeMs = (hrtime(true) - $startedAt) / 1_000_000;

        return $executed;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $value = parent::fetchColumn($column);
        $this->tail = is_int($value) || is_string($value) ? (string) $value : 'EOF';

        return $value;
    }

    public function closeCursor(): bool
    {
        $closed = parent::closeCursor();
        if ($closed) {
            fwrite(STDOUT, sprintf("TAIL %d %s %.3F\n", $this->reads, $this->tail, $this->executeMs));
            fflush(STDOUT);
            if ($this->reads <= 2 && self::readCommand() !== "CONTINUE\n") {
                throw new RuntimeException('The parent did not acknowledge the observed tail read.');
            }
        }

        return $closed;
    }

    /** Reads a complete barrier command sent by PartnerProcess, with a bounded wait. */
    public static function readCommand(): string|false
    {
        $read = [STDIN];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, 15) === 1 ? fgets(STDIN) : false;
    }
}
