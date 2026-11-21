<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use PDO;
use Pdo\Mysql;

abstract class HostSnapshotIntegrationTestCase extends PaginationIntegrationTestCase
{
    /** A Host connection that the test fixture does not retain. */
    protected function borrowableConnection(
        bool $emulated = false,
        bool $persistent = false,
        bool $mysqlClass = false,
    ): PDO {
        $dsn = 'mysql:host=' . self::setting('HOST') . ';port=' . self::setting('PORT') . ';dbname=' . self::setting('DATABASE');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => $emulated,
            PDO::ATTR_PERSISTENT => $persistent,
            Mysql::ATTR_USE_BUFFERED_QUERY => false,
        ];

        return $mysqlClass
            ? new Mysql($dsn, self::setting('USER'), self::setting('PASSWORD'), $options)
            : new PDO($dsn, self::setting('USER'), self::setting('PASSWORD'), $options);
    }
}
