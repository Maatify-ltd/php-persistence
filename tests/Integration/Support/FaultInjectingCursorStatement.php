<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Support;

use PDOStatement;
use Throwable;

/** Delegates to live pdo_mysql; injects errors only on the B1 shift statement. */
final class FaultInjectingCursorStatement extends PDOStatement
{
    protected function __construct(
        private readonly Throwable $closeFailure,
        private readonly ?Throwable $executeFailure,
    ) {}

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $executed = parent::execute($params);
        if ($executed && $this->executeFailure !== null && $this->isShift()) {
            throw $this->executeFailure;
        }

        return $executed;
    }

    public function closeCursor(): bool
    {
        $closed = parent::closeCursor();
        if ($closed && $this->isShift()) {
            throw $this->closeFailure;
        }

        return $closed;
    }

    private function isShift(): bool
    {
        return str_starts_with($this->queryString, 'UPDATE ')
            && str_contains($this->queryString, ' BETWEEN :first_position AND :last_position');
    }
}
