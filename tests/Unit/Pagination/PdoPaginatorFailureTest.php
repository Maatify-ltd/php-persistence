<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Pagination;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdoStatement;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class PdoPaginatorFailureTest extends TestCase
{
    public function testTotalPrepareFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([false]), 1);
    }

    public function testFilteredPrepareFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), false]), 2);
    }

    public function testDataPrepareFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), false]), 3);
    }

    public function testTotalBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([$this->countStatement(bindResults: [':p' => false])]), 1);
    }

    public function testFilteredBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), $this->countStatement(bindResults: [':p' => false])]), 2);
    }

    public function testDataBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $this->dataStatement(bindResults: [':p' => false])]), 3);
    }

    public function testInternalLimitBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $this->dataStatement(bindResults: [':__pagination_limit' => false])]), 3);
    }

    public function testInternalOffsetBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $this->dataStatement(bindResults: [':__pagination_offset' => false])]), 3);
    }

    public function testTotalExecuteFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([$this->countStatement(executeResult: false)]), 1);
    }

    public function testFilteredExecuteFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), $this->countStatement(executeResult: false)]), 2);
    }

    public function testDataExecuteFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $this->dataStatement(executeResult: false)]), 3);
    }

    public function testNormalEofProducesResult(): void
    {
        $data = ScriptedPdoStatement::data([['id' => 1]]);
        $result = (new PdoPaginator())->paginate(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $data]), $this->queryWithoutParams(), new PageRequest(), $this->config(), static fn(array $row): array => $row);
        self::assertSame([['id' => 1]], $result->data);
        self::assertSame(2, $data->fetchCalls);
        self::assertSame(0, $data->closeCursorCalls);
    }

    public function testFetchFalseWithNonSuccessSqlstate(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([$this->countStatement(fetchQueue: [false], errorCode: 'HY000')]), 1);
    }

    public function testInvalidScalarFetch(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [123], true, '00000')]), 1);
    }

    public function testNumericKeyRow(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [[1], false])]), 1);
    }

    public function testEmptyDataRow(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), new ScriptedPdoStatement(1, [[], false])]), 3);
    }

    public function testCountZeroRows(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [false])]), 1);
    }

    public function testCountMultipleRows(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [['total_count' => 1], ['total_count' => 2], false])]), 1);
    }

    public function testCountMultipleColumns(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(2, [['a' => 1, 'b' => 2], false])]), 1);
    }

    public function testInvalidCountRepresentations(): void
    {
        foreach ([false, null, '', '-1', '+1', '1.2', '1e2', PHP_INT_MAX . '0', []] as $value) {
            $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [['total_count' => $value], false])]), 1);
        }
    }

    public function testDataFetchFailureAfterMappedRowDoesNotReturnPartialResult(): void
    {
        $data = new ScriptedPdoStatement(1, [['id' => 1], false], true, 'HY000');
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $data]), 3);
        self::assertSame(2, $data->fetchCalls);
        self::assertSame(1, $data->closeCursorCalls);
    }

    /** @return array<string, array{bool|PDOException}> */
    public static function cursorCleanupOutcomes(): array
    {
        return [
            'cursor closed' => [true],
            'close returns false' => [false],
            'close throws' => [new PDOException('cursor cleanup failed')],
        ];
    }

    #[DataProvider('cursorCleanupOutcomes')]
    public function testCursorCleanupDoesNotReplaceDataFetchException(bool|PDOException $cleanupOutcome): void
    {
        $failure = new PDOException('data fetch failed');
        $data = new ScriptedPdoStatement(fetchQueue: [['id' => 1], $failure], closeCursorResult: $cleanupOutcome);
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(2), ScriptedPdoStatement::count(2), $data]);

        try {
            (new PdoPaginator())->paginate($pdo, $this->queryWithoutParams(), new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected the data fetch exception.');
        } catch (PDOException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(2, $data->fetchCalls);
        self::assertSame(1, $data->closeCursorCalls);
        self::assertCount(3, $pdo->preparedSql);
    }

    #[DataProvider('cursorCleanupOutcomes')]
    public function testCursorCleanupDoesNotReplaceMapperException(bool|PDOException $cleanupOutcome): void
    {
        $failure = new RuntimeException('mapper failed');
        $data = new ScriptedPdoStatement(fetchQueue: [['id' => 1], ['id' => 2], false], closeCursorResult: $cleanupOutcome);
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(2), ScriptedPdoStatement::count(2), $data]);

        try {
            (new PdoPaginator())->paginate($pdo, $this->queryWithoutParams(), new PageRequest(), $this->config(), static fn(array $row): never => throw $failure);
            self::fail('Expected the mapper exception.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $data->fetchCalls);
        self::assertSame(1, $data->closeCursorCalls);
        self::assertCount(3, $pdo->preparedSql);
    }

    public function testPdoExceptionIdentityFromPrepareBindExecuteAndFetch(): void
    {
        foreach ([$this->pdoPrepareThrowable(), $this->pdoBindThrowable(), $this->pdoExecuteThrowable(), $this->pdoFetchThrowable()] as [$pdo, $exception]) {
            try {
                (new PdoPaginator())->paginate($pdo, $this->query(), new PageRequest(), $this->config(), static fn(array $row): array => $row);
                self::fail('Expected PDOException.');
            } catch (PDOException $caught) {
                self::assertSame($exception, $caught);
            }
        }
    }

    public function testMapperThrowableIdentityAndInvalidMapperResults(): void
    {
        $exception = new RuntimeException('mapper');
        try {
            (new PdoPaginator())->paginate($this->successfulPdo(), $this->queryWithoutParams(), new PageRequest(), $this->config(), static fn(array $row) => throw $exception);
            self::fail('Expected mapper exception.');
        } catch (RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }

        $this->expectException(PaginationExecutionException::class);
        $this->invokePaginatorWithMapper(static fn(array $row): int => 1);
    }

    public function testResourceMapperResultIsRejectedAndClosed(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        try {
            $this->expectException(PaginationExecutionException::class);
            $this->invokePaginatorWithMapper(static fn(array $row) => $resource);
        } finally {
            fclose($resource);
        }
    }

    private function assertExecutionFailure(ScriptedPdo $pdo, int $prepareCount): void
    {
        try {
            (new PdoPaginator())->paginate($pdo, $this->query(), new PageRequest(), $this->config(), static fn(array $row): array => $row);
            self::fail('Expected PaginationExecutionException.');
        } catch (PaginationExecutionException $exception) {
            self::assertInstanceOf(PaginationExecutionException::class, $exception);
            self::assertCount($prepareCount, $pdo->preparedSql);
        }
    }

    private function invokePaginatorWithMapper(callable $mapper): void
    {
        (new ReflectionMethod(PdoPaginator::class, 'paginate'))->invokeArgs(new PdoPaginator(), [$this->successfulPdo(), $this->queryWithoutParams(), new PageRequest(), $this->config(), $mapper]);
    }

    private function query(): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor('T', ['p' => 1], 'F', ['p' => 1], 'D', ['p' => 1]);
    }

    private function queryWithoutParams(): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor('T', [], 'F', [], 'D', []);
    }

    private function config(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'id']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC);
    }

    private function successfulPdo(): ScriptedPdo
    {
        return new ScriptedPdo([
            ScriptedPdoStatement::count(1),
            ScriptedPdoStatement::count(1),
            ScriptedPdoStatement::data([['id' => 1]]),
        ]);
    }

    /**
     * @param list<mixed> $fetchQueue
     * @param array<string, bool|PDOException> $bindResults
     */
    private function countStatement(
        array $fetchQueue = [['total_count' => 1], false],
        bool|PDOException $executeResult = true,
        ?string $errorCode = '00000',
        array $bindResults = [],
    ): ScriptedPdoStatement {
        return new ScriptedPdoStatement(1, $fetchQueue, $executeResult, $errorCode, $bindResults);
    }

    /** @param array<string, bool|PDOException> $bindResults */
    private function dataStatement(bool|PDOException $executeResult = true, array $bindResults = []): ScriptedPdoStatement
    {
        return new ScriptedPdoStatement(1, [['id' => 1], false], $executeResult, '00000', $bindResults);
    }

    /** @return array{ScriptedPdo, PDOException} */
    private function pdoPrepareThrowable(): array
    {
        $exception = new PDOException('prepare');

        return [new ScriptedPdo([$exception]), $exception];
    }

    /** @return array{ScriptedPdo, PDOException} */
    private function pdoBindThrowable(): array
    {
        $exception = new PDOException('bind');

        return [new ScriptedPdo([$this->countStatement(bindResults: [':p' => $exception])]), $exception];
    }

    /** @return array{ScriptedPdo, PDOException} */
    private function pdoExecuteThrowable(): array
    {
        $exception = new PDOException('execute');

        return [new ScriptedPdo([$this->countStatement(executeResult: $exception)]), $exception];
    }

    /** @return array{ScriptedPdo, PDOException} */
    private function pdoFetchThrowable(): array
    {
        $exception = new PDOException('fetch');

        return [new ScriptedPdo([new ScriptedPdoStatement(1, [$exception])]), $exception];
    }
}
