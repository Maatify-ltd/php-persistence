<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandEffectEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandResultModeEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;

/**
 * Registers one trusted SQL command and declares its bindings, result mode
 * and effect on the page-authorization generation. The Host owns the SQL
 * semantics and must declare a preserving effect only for a command that
 * cannot change the session or current transaction.
 */
final readonly class HostSnapshotCommandPlan
{
    /** @var list<string> @internal */
    public array $parameterNames;

    /**
     * Accepts a non-blank, semicolon-free statement and a list of unique bare
     * binding names. Bounded rows require a positive limit and preserve the
     * associative keys from PDO. Affected-row mode ignores the limit. The
     * default effect invalidates the generation before the command is sent.
     *
     * @param array<array-key, mixed> $parameterNames
     */
    public function __construct(
        /** @internal */
        public string $sql,
        array $parameterNames,
        /** @internal */
        public HostSnapshotCommandResultModeEnum $resultMode,
        /** @internal */
        public ?int $maxRows = null,
        /** @internal */
        public HostSnapshotCommandEffectEnum $effect = HostSnapshotCommandEffectEnum::INVALIDATE_GENERATION,
    ) {
        if (trim($sql) === '' || str_contains($sql, ';')
            || ($resultMode === HostSnapshotCommandResultModeEnum::BOUNDED_ROWS && ($maxRows === null || $maxRows < 1))
        ) {
            throw new HostSnapshotPaginationException('Invalid Host snapshot command plan.');
        }
        $this->parameterNames = HostSnapshotBindings::names($parameterNames);
    }
}
