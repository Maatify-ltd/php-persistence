<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum;
use Maatify\PhpPersistence\Pagination\Exception\SnapshotPaginationException;

/** Terminal disposition and optional safe diagnostic, separate from the page. */
final readonly class SnapshotConnectionOutcomeDTO
{
    public function __construct(
        public SnapshotConnectionDispositionEnum $disposition,
        public ?string $diagnostic,
    ) {
        if ($disposition === SnapshotConnectionDispositionEnum::DISCARD && ($diagnostic === null || trim($diagnostic) === '')) {
            throw new SnapshotPaginationException('A discard outcome requires a diagnostic.');
        }
    }
}
