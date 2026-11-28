<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\DTO\HostSnapshotPageSettlementDTO;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageAttemptStateEnum as State;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageDispositionEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Repository\HostSnapshotAttemptRecord;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use WeakReference;

/**
 * One opaque invocation for a source-authorized page grant. Keep it to inspect
 * its immutable, connection-free settlement after the paginator returns or
 * throws, including after the source releases or retires the checkout.
 */
final readonly class HostSnapshotPageAttempt
{
    /** @param WeakReference<PdoHostSnapshotSource> $source */
    private function __construct(private WeakReference $source, private HostSnapshotAttemptRecord $record) {}

    /**
     * @internal An attempt without a source registration cannot read.
     * @param WeakReference<PdoHostSnapshotSource> $source
     */
    public static function issue(WeakReference $source, HostSnapshotAttemptRecord $record): self
    {
        return new self($source, $record);
    }

    /**
     * Reads the recorded state without database I/O. An attempt starts New,
     * becomes Running on admission, and becomes Terminal when settled. An
     * attempt rejected before admission can go directly from New to Terminal
     * without performing database I/O.
     */
    public function state(): State
    {
        return $this->record->state;
    }

    /**
     * Returns null until the attempt is terminal, then the same immutable
     * outcome even after later invocations or disposal of the source.
     */
    public function settlement(): ?HostSnapshotPageSettlementDTO
    {
        return $this->record->settlement;
    }

    /**
     * Invokes the attempt once. A missing source settles it as not accepted.
     * A repeat invocation rejects without replacing the earlier settlement.
     *
     * @internal
     * @return PageResultDTO<array<array-key, mixed>|object>
     */
    public function read(): PageResultDTO
    {
        if ($this->state() !== State::New) {
            throw new HostSnapshotPaginationException('The Host snapshot attempt was already invoked.');
        }
        $source = $this->source->get();
        if ($source === null) {
            $this->record->finish(new HostSnapshotPageSettlementDTO(
                HostSnapshotPageDispositionEnum::NotAccepted,
                HostSnapshotPageSettlementReasonEnum::SourceUnavailable,
            ));
            throw new HostSnapshotPaginationException('The Host snapshot source is unavailable.');
        }

        return $source->readAttempt($this);
    }

    private function __clone() {}

    /**
     * @return array<array-key, mixed>
     *
     * @throws HostSnapshotPaginationException Always. The source tracks this invocation and its settlement by object identity.
     */
    public function __serialize(): array
    {
        throw new HostSnapshotPaginationException('A Host snapshot attempt cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws HostSnapshotPaginationException Always. A page attempt must be registered with its source.
     */
    public function __unserialize(array $data): void
    {
        throw new HostSnapshotPaginationException('A Host snapshot attempt cannot be restored.');
    }
}
