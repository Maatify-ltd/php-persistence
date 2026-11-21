<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Pagination;

use LogicException;
use Maatify\PhpPersistence\Pagination\DTO\SnapshotConnectionOutcomeDTO;
use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum;
use Maatify\PhpPersistence\Pagination\Repository\PdoSnapshotPaginator;
use PDO;
use Throwable;

/** A test Host that holds real connections out of circulation until settlement. */
final class SnapshotHostCheckout
{
    private ?int $outstanding = null;
    private ?PDO $delegated = null;
    private ?PDO $returnedToHost = null;
    private ?PdoSnapshotPaginator $operation = null;
    private int $sequence = 0;

    public function __construct(private ?PDO $available, private readonly bool $knownClean = true) {}

    /** @return array{int, PDO, PdoSnapshotPaginator} */
    public function checkout(): array
    {
        if (! $this->knownClean || $this->available === null) {
            throw new LogicException('No verified checkout can be issued.');
        }
        $pdo = $this->available;
        $this->available = null;
        $this->outstanding = ++$this->sequence;
        $this->delegated = $pdo;
        $this->operation = PdoSnapshotPaginator::forExclusiveRead($pdo);

        return [$this->sequence, $pdo, $this->operation];
    }

    public function settle(int $checkout, ?SnapshotConnectionOutcomeDTO $outcome, ?Throwable $failure = null): void
    {
        if ($this->outstanding !== $checkout) {
            throw new LogicException('Checkout is not outstanding.');
        }

        if ($outcome !== null && $outcome !== $this->operation?->connectionOutcome()) {
            throw new LogicException('The outcome does not belong to this checkout.');
        }

        $pdo = $this->delegated;
        $this->outstanding = null;
        $this->delegated = null;
        $this->operation = null;
        if ($failure !== null) {
            throw $failure;
        }
        if ($outcome?->disposition === SnapshotConnectionDispositionEnum::REUSABLE) {
            $this->available = $pdo;
        } elseif ($outcome?->disposition === SnapshotConnectionDispositionEnum::UNCHANGED) {
            $this->returnedToHost = $pdo;
        }
    }

    public function takeReturnedConnection(): ?PDO
    {
        $pdo = $this->returnedToHost;
        $this->returnedToHost = null;

        return $pdo;
    }

    public function canCheckout(): bool
    {
        return $this->knownClean && $this->available !== null;
    }
}
