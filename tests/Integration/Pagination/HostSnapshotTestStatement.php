<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use PDOStatement;

/** Custom PDO statement type used to verify borrowed connection eligibility. */
final class HostSnapshotTestStatement extends PDOStatement {}
