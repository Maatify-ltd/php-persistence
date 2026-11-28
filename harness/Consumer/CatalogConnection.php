<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Verification\Consumer;

use Closure;
use PDO;
use PDOStatement;

/**
 * A PDO connection that can coordinate a second real connection before a specific query.
 */
final class CatalogConnection extends PDO
{
    /** Called before the driver prepares the statement to coordinate the second fixture connection. */
    public ?Closure $beforePrepare = null;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ($this->beforePrepare)?->__invoke($query);

        return parent::prepare($query, $options);
    }
}
