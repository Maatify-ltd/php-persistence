<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Exception;

use RuntimeException;

/** @internal Signals a bounded result whose cursor still needs normal settlement. */
final class HostSnapshotRowLimitExceeded extends RuntimeException {}
