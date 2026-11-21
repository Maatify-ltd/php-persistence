<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandEffectEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotCommandResultModeEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;

/** One trusted Host-registered SQL command. */
final readonly class HostSnapshotCommandPlan
{
    /** @var list<string> @internal */
    public array $parameterNames;

    /** @param array<array-key, mixed> $parameterNames */
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
