<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tooling;

use Exception;

/**
 * A catchable signal that unwinds owned work before Compose teardown.
 */
final class IntegrationInterrupted extends Exception {}
