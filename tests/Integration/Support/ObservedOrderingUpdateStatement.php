<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Support;

use Closure;
use PDOStatement;

/** Records affected-row counts for real UPDATE statements. */
final class ObservedOrderingUpdateStatement extends PDOStatement
{
    /** @param Closure(int): void $observe */
    protected function __construct(private readonly Closure $observe) {}

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $executed = parent::execute($params);
        if ($executed && str_starts_with($this->queryString, 'UPDATE ')) {
            ($this->observe)(parent::rowCount());
        }

        return $executed;
    }
}
