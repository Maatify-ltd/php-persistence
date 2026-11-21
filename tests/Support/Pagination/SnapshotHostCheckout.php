<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Pagination;

use LogicException;
use Maatify\PhpPersistence\Pagination\DTO\SnapshotConnectionOutcomeDTO;
use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum;
use PDO;
use Throwable;

/** A test Host that holds real connections out of circulation until settlement. */
final class SnapshotHostCheckout
{
    private ?int $outstanding = null;
    private ?PDO $delegated = null;
    private int $sequence = 0;

    public function __construct(private ?PDO $available, private readonly bool $knownClean = true) {}

    /** @return array{int, PDO} */
    public function checkout(): array
    {
        if (! $this->knownClean || $this->available === null) {
            throw new LogicException('No verified checkout can be issued.');
        }
        $pdo = $this->available;
        $this->available = null;
        $this->outstanding = ++$this->sequence;
        $this->delegated = $pdo;

        return [$this->sequence, $pdo];
    }

    public function settle(int $checkout, ?SnapshotConnectionOutcomeDTO $outcome, ?Throwable $failure = null): void
    {
        if ($this->outstanding !== $checkout) {
            throw new LogicException('Checkout is not outstanding.');
        }

        $pdo = $this->delegated;
        $this->outstanding = null;
        $this->delegated = null;
        if ($failure !== null) {
            throw $failure;
        }
        if ($outcome?->disposition === SnapshotConnectionDispositionEnum::REUSABLE) {
            $this->available = $pdo;
        }
    }

    public function canCheckout(): bool
    {
        return $this->knownClean && $this->available !== null;
    }
}
