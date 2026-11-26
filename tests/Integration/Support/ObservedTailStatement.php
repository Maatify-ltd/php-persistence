<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Support;

use Closure;
use PDOStatement;
use RuntimeException;

/** Reports real locking reads without changing any SQL or returned values. */
final class ObservedTailStatement extends PDOStatement
{
    private int $reads = 0;
    private float $executeMs = 0.0;
    private string $tail = 'EOF';

    /** @var array<mixed>|null */
    private ?array $params = null;

    /** @param (Closure(int, string, string, array<mixed>|null): bool)|null $afterClose */
    protected function __construct(private readonly ?Closure $afterClose = null) {}

    /** @param array<mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $limit = $this->afterClose === null ? 8 : 514;
        if (++$this->reads > $limit) {
            throw new RuntimeException('The observed tail-read fixture exceeded its statement limit.');
        }

        $this->params = $params;
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

    /**
     * Notifies the tail-read barrier only after native cursor close succeeds.
     * A fixture callback can control the returned acknowledgement, and the
     * process harness waits for CONTINUE on the first two observed reads.
     */
    public function closeCursor(): bool
    {
        $closed = parent::closeCursor();
        if ($closed) {
            if ($this->afterClose !== null) {
                return ($this->afterClose)($this->reads, $this->tail, $this->queryString, $this->params);
            }
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
