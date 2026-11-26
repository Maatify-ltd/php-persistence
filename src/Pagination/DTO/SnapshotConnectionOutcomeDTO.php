<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum;
use Maatify\PhpPersistence\Pagination\Exception\SnapshotPaginationException;

/** Terminal disposition and optional safe diagnostic, separate from the page. */
final readonly class SnapshotConnectionOutcomeDTO
{
    /**
     * Records the terminal disposition independently of the page result or
     * throwable. DISCARD requires a non-blank diagnostic describing the
     * unconfirmed stage, so the Host can retire the checkout.
     */
    public function __construct(
        public SnapshotConnectionDispositionEnum $disposition,
        public ?string $diagnostic,
    ) {
        if ($disposition === SnapshotConnectionDispositionEnum::DISCARD && ($diagnostic === null || trim($diagnostic) === '')) {
            throw new SnapshotPaginationException('A discard outcome requires a diagnostic.');
        }
    }
}
