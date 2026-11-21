<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Criteria;

use Maatify\PhpPersistence\Pagination\DTO\HostSnapshotPageSettlementDTO;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageAttemptStateEnum as State;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageDispositionEnum;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum;
use Maatify\PhpPersistence\Pagination\Exception\HostSnapshotPaginationException;
use Maatify\PhpPersistence\Pagination\Repository\PdoHostSnapshotSource;
use WeakReference;

/** One opaque invocation with a durable, connection-free settlement. */
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

    public function state(): State
    {
        return $this->record->state;
    }

    public function settlement(): ?HostSnapshotPageSettlementDTO
    {
        return $this->record->settlement;
    }

    /**
     * @internal
     * @return PageResultDTO<array<array-key, mixed>|object>
     */
    public function read(): PageResultDTO
    {
        if ($this->state() !== State::NEW) {
            throw new HostSnapshotPaginationException('The Host snapshot attempt was already invoked.');
        }
        $source = $this->source->get();
        if ($source === null) {
            $this->record->finish(new HostSnapshotPageSettlementDTO(
                HostSnapshotPageDispositionEnum::NOT_ACCEPTED,
                HostSnapshotPageSettlementReasonEnum::SOURCE_UNAVAILABLE,
            ));
            throw new HostSnapshotPaginationException('The Host snapshot source is unavailable.');
        }

        return $source->readAttempt($this);
    }

    private function __clone() {}

    /** @return array<array-key, mixed> */
    public function __serialize(): array
    {
        throw new HostSnapshotPaginationException('A Host snapshot attempt cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new HostSnapshotPaginationException('A Host snapshot attempt cannot be restored.');
    }
}
