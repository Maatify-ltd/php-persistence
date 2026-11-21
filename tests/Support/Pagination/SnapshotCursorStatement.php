<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Pagination;

use Closure;
use PDO;
use PDOStatement;

/** Performs real database reads, with a narrowly scoped closeCursor fault hook. */
final class SnapshotCursorStatement extends PDOStatement
{
    /**
     * @param Closure(string): bool $canClose
     * @param (Closure(string): void)|null $beforeFetch
     */
    protected function __construct(
        private readonly Closure $canClose,
        private readonly ?Closure $beforeFetch = null,
    ) {}

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        ($this->beforeFetch)?->__invoke($this->queryString);

        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function closeCursor(): bool
    {
        return ($this->canClose)($this->queryString) && parent::closeCursor();
    }
}
