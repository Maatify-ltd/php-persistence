<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use PDO;
use Pdo\Mysql;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class PdoPaginatorFailureContractTest extends PaginationIntegrationTestCase
{
    /** @return array<string, array{bool}> */
    public static function bufferingModes(): array
    {
        return ['buffered' => [true], 'unbuffered' => [false]];
    }

    #[DataProvider('bufferingModes')]
    public function testSameConnectionCanBeReusedAfterMapperThrows(bool $buffered): void
    {
        [$pdo, $table] = $this->beginPaginationTransaction($buffered);
        $failure = new RuntimeException('mapper failed on the first row');

        try {
            try {
                (new PdoPaginator())->paginate(
                    $pdo,
                    $this->descriptor($table),
                    new PageRequest(),
                    $this->config(),
                    static fn(array $row): never => throw $failure,
                );
                self::fail('Expected the mapper failure.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }

            $this->assertSameConnectionCanBeReused($pdo, $table);
        } finally {
            $pdo->rollBack();
        }
    }

    #[DataProvider('bufferingModes')]
    public function testSameConnectionCanBeReusedAfterInvalidMappedItem(bool $buffered): void
    {
        [$pdo, $table] = $this->beginPaginationTransaction($buffered);

        try {
            try {
                $this->invokePaginatorWithMapper($pdo, $table, static fn(array $row): int => 1);
                self::fail('Expected an invalid mapped item failure.');
            } catch (PaginationExecutionException $caught) {
                self::assertSame('Pagination mapper must return array or object.', $caught->getMessage());
            }

            $this->assertSameConnectionCanBeReused($pdo, $table);
        } finally {
            $pdo->rollBack();
        }
    }

    #[DataProvider('bufferingModes')]
    public function testSameConnectionCanBeReusedWhileRetainingRowValidationException(bool $buffered): void
    {
        [$pdo, $table] = $this->beginPaginationTransaction($buffered);
        $query = $this->descriptor($table);
        $invalidRowQuery = new PdoPaginationQueryDescriptor(
            $query->totalSql,
            $query->totalParams,
            $query->filteredCountSql,
            $query->filteredCountParams,
            "SELECT id AS `0`, created_at FROM `$table` WHERE tenant_id = :tenant_data AND deleted_at IS NULL AND is_active = :active_data",
            $query->dataParams,
        );

        $this->assertConnectionReusableWithRetainedRowKeyException($pdo, $table, $invalidRowQuery);
    }

    #[DataProvider('bufferingModes')]
    public function testSameConnectionCanBeReusedWhileRetainingTotalCountValidationException(bool $buffered): void
    {
        [$pdo, $table] = $this->beginPaginationTransaction($buffered);
        $query = $this->descriptor($table);
        $invalidCountQuery = new PdoPaginationQueryDescriptor(
            "SELECT id AS `0` FROM `$table` WHERE tenant_id = :tenant_total AND deleted_at IS NULL",
            $query->totalParams,
            $query->filteredCountSql,
            $query->filteredCountParams,
            $query->dataSql,
            $query->dataParams,
        );

        $this->assertConnectionReusableWithRetainedRowKeyException($pdo, $table, $invalidCountQuery);
    }

    public function testCountShapeFailures(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);

        $descriptors = [
            new PdoPaginationQueryDescriptor("SELECT id AS total_count FROM `$table` WHERE tenant_id = 999", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
            new PdoPaginationQueryDescriptor("SELECT id AS total_count FROM `$table` WHERE tenant_id = 1", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
            new PdoPaginationQueryDescriptor("SELECT id, name FROM `$table` WHERE tenant_id = 1 LIMIT 1", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
        ];

        foreach ($descriptors as $descriptor) {
            try {
                (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
                self::fail('Expected count shape failure.');
            } catch (PaginationExecutionException $exception) {
                self::assertInstanceOf(PaginationExecutionException::class, $exception);
            }
        }
    }

    public function testRepeatedNamedPlaceholder(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table` WHERE name = :p OR category = :p", ['p' => 'test']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testMissingParameter(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table` WHERE name = :p", []);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testUnusedParameter(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", ['p' => 'unused']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testPositionalPlaceholder(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table` WHERE name = ?", []);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testMixedPositionalAndNamedPlaceholders(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table` WHERE name = ? AND category = :cat", ['cat' => 'test']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testInvalidTable(): void
    {
        [$pdo] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count FROM missing_pagination_table', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM missing_pagination_table', []);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected PDOException.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(PDOException::class, $exception);
        }
    }

    public function testInvalidColumn(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $descriptor = new PdoPaginationQueryDescriptor("SELECT COUNT(missing_col) AS total_count FROM `$table`", [], 'SELECT 1 AS filtered_count', [], "SELECT missing_col FROM `$table`", []);

        try {
            (new PdoPaginator())->paginate($pdo, $descriptor, new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected PDOException.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(PDOException::class, $exception);
        }
    }

    public function testMapperThrowableIdentityAndInvalidMapperResults(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        $throwable = new RuntimeException('mapper');

        try {
            (new PdoPaginator())->paginate($pdo, $this->descriptor($table), new PageRequest(), $this->config(), static fn(array $row) => throw $throwable);
            self::fail('Expected mapper throwable.');
        } catch (RuntimeException $caught) {
            self::assertSame($throwable, $caught);
        }

        $this->expectException(PaginationExecutionException::class);
        $this->invokePaginatorWithMapper($pdo, $table, static fn(array $row): int => 1);
    }

    public function testResourceMapperFailureClosesResource(): void
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(PaginationExecutionException::class);
            $this->invokePaginatorWithMapper($pdo, $table, static fn(array $row) => $resource);
        } finally {
            fclose($resource);
        }
    }

    /** @return array{PDO, string} */
    private function beginPaginationTransaction(bool $buffered): array
    {
        [$pdo, $table] = $this->connectWithPaginationTable();
        $this->insertMultiTenantDataset($pdo, $table);
        self::assertTrue($pdo->setAttribute(Mysql::ATTR_USE_BUFFERED_QUERY, $buffered));
        self::assertSame($buffered, (bool) $pdo->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY));
        self::assertTrue($pdo->beginTransaction());

        return [$pdo, $table];
    }

    private function assertConnectionReusableWithRetainedRowKeyException(PDO $pdo, string $table, PdoPaginationQueryDescriptor $query): void
    {
        // Keep exception arguments enabled so a retained trace can keep a statement alive.
        $previousSetting = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previousSetting);

        try {
            try {
                (new PdoPaginator())->paginate($pdo, $query, new PageRequest(), $this->config(), static fn(array $row): array => $row);
                self::fail('Expected an invalid row failure.');
            } catch (PaginationExecutionException $caught) {
                self::assertSame('Pagination row keys must be strings.', $caught->getMessage());
            }

            $this->assertSameConnectionCanBeReused($pdo, $table);
        } finally {
            // Release the trace only after the reuse attempt, so test teardown can roll back.
            unset($caught);
            ini_set('zend.exception_ignore_args', $previousSetting);
            $pdo->rollBack();
        }
    }

    private function assertSameConnectionCanBeReused(PDO $pdo, string $table): void
    {
        self::assertTrue($pdo->inTransaction());
        self::assertSame('6', $this->scalar($pdo, "SELECT COUNT(*) FROM `$table`"));
        self::assertTrue($pdo->inTransaction());
    }

    private function invokePaginatorWithMapper(PDO $pdo, string $table, callable $mapper): void
    {
        $method = new ReflectionMethod(PdoPaginator::class, 'paginate');
        $method->invokeArgs(new PdoPaginator(), [
            $pdo,
            $this->descriptor($table),
            new PageRequest(),
            $this->config(),
            $mapper,
        ]);
    }
}
