<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Pagination;

use Closure;
use PDOStatement;

/** Performs real database reads, with a narrowly scoped closeCursor fault hook. */
final class SnapshotCursorStatement extends PDOStatement
{
    /** @param Closure(string): bool $canClose */
    protected function __construct(private readonly Closure $canClose) {}

    public function closeCursor(): bool
    {
        return ($this->canClose)($this->queryString) && parent::closeCursor();
    }
}
