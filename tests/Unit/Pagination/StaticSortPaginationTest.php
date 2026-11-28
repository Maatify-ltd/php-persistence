<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Pagination;

use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Config\StaticSortPlan;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum as Direction;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdoStatement;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class StaticSortPaginationTest extends TestCase
{
    private const string DATA = 'SELECT p.id FROM positions p WHERE tenant_id = :data_tenant';

    public function testApprovedSignatures(): void
    {
        $plan = new ReflectionClass(StaticSortPlan::class);
        self::assertTrue($plan->isFinal());
        self::assertTrue($plan->isReadOnly());
        $constructor = $plan->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(['config', 'profiles'], array_map(static fn($parameter): string => $parameter->name, $constructor->getParameters()));
        self::assertSame([PaginationConfig::class, 'array'], array_map(static fn($parameter): string => (string) $parameter->getType(), $constructor->getParameters()));

        $method = new ReflectionMethod(PdoPaginator::class, 'paginateWithStaticSort');
        self::assertTrue($method->isPublic());
        self::assertSame(['pdo', 'query', 'request', 'plan', 'mapper'], array_map(static fn($parameter): string => $parameter->name, $method->getParameters()));
        self::assertSame([PDO::class, PdoPaginationQueryDescriptor::class, PageRequest::class, StaticSortPlan::class, 'callable'], array_map(static fn($parameter): string => (string) $parameter->getType(), $method->getParameters()));
        self::assertSame(5, $method->getNumberOfRequiredParameters());
        self::assertSame(PageResultDTO::class, (string) $method->getReturnType());
    }

    public function testReusablePlanProducesBoundSqlAndExistingResultEnvelope(): void
    {
        $plan = self::plan();
        $paginator = new PdoPaginator();
        foreach ([Direction::Asc, Direction::Desc] as $direction) {
            $total = ScriptedPdoStatement::count(10);
            $filtered = ScriptedPdoStatement::count(3);
            $data = ScriptedPdoStatement::data([['id' => 7]]);
            $pdo = new ScriptedPdo([$total, $filtered, $data]);
            $page = $paginator->paginateWithStaticSort(
                pdo: $pdo,
                query: self::query(),
                request: new PageRequest(page: 2, perPage: 2, sortBy: ' business_order ', sortDirection: strtolower($direction->value)),
                plan: $plan,
                mapper: static fn(array $row): array => $row,
            );

            self::assertSame([
                'TOTAL :total_tenant',
                'FILTERED :filtered_tenant',
                self::dataSql('ORDER BY `p`.`scope_key` ' . $direction->value . ', `p`.`position` ' . $direction->value . ', `p`.`id` ASC'),
            ], $pdo->preparedSql);
            self::assertSame([['parameter' => ':total_tenant', 'value' => 1, 'type' => PDO::PARAM_INT]], $total->bindCalls);
            self::assertSame([['parameter' => ':filtered_tenant', 'value' => 2, 'type' => PDO::PARAM_INT]], $filtered->bindCalls);
            self::assertSame([
                ['parameter' => ':data_tenant', 'value' => 2, 'type' => PDO::PARAM_INT],
                ['parameter' => ':__pagination_limit', 'value' => 2, 'type' => PDO::PARAM_INT],
                ['parameter' => ':__pagination_offset', 'value' => 2, 'type' => PDO::PARAM_INT],
            ], $data->bindCalls);
            self::assertSame([
                'data' => [['id' => 7]],
                'pagination' => [
                    'page' => 2,
                    'per_page' => 2,
                    'total' => 10,
                    'filtered' => 3,
                    'total_pages' => 2,
                    'has_next' => false,
                    'has_previous' => true,
                    'sort_by' => 'business_order',
                    'sort_direction' => $direction->value,
                ],
            ], $page->toArray());
            self::assertSame(json_encode($page->toArray(), JSON_THROW_ON_ERROR), json_encode($page, JSON_THROW_ON_ERROR));
            self::assertSame([0, 0, 0, []], [$pdo->beginTransactionCalls, $pdo->commitCalls, $pdo->rollBackCalls, $pdo->setAttributeCalls]);
        }
    }

    public function testThreeTrailingTermsUseTrustedIdentifiersAndKeepPrimaryDirection(): void
    {
        $plan = new StaticSortPlan(self::config(), [
            'business_order' => [
                ['identifier' => 'catalog.positions.rank', 'direction' => Direction::Asc],
                ['identifier' => 'projected_rank'],
                ['identifier' => 'p.id', 'direction' => Direction::Desc],
            ],
        ]);
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), ScriptedPdoStatement::data([['id' => 1]])]);

        $page = (new PdoPaginator())->paginateWithStaticSort($pdo, self::query(), new PageRequest(sortDirection: 'desc'), $plan, static fn(array $row): array => $row);

        self::assertSame(self::dataSql('ORDER BY `p`.`scope_key` DESC, `catalog`.`positions`.`rank` ASC, `projected_rank` DESC, `p`.`id` DESC'), $pdo->preparedSql[2]);
        self::assertSame('business_order', $page->sortBy);
        self::assertSame(Direction::Desc, $page->sortDirection);
    }

    /** @return array<string, array{?string, ?string, string, Direction, string}> */
    public static function requestOrders(): array
    {
        return [
            'unknown key retains valid direction' => ['missing', ' desc ', 'business_order', Direction::Desc, 'ORDER BY `p`.`scope_key` DESC, `p`.`position` DESC, `p`.`id` ASC'],
            'known key retains independent direction fallback' => [' id ', 'unsupported', 'id', Direction::Asc, 'ORDER BY `p`.`id` ASC'],
            'null profile de-duplicates the legacy tie' => ['id', 'DESC', 'id', Direction::Desc, 'ORDER BY `p`.`id` DESC'],
            'null profile works as the configured default' => [null, 'DESC', 'business_order', Direction::Desc, 'ORDER BY `p`.`scope_key` DESC, `p`.`id` ASC'],
        ];
    }

    #[DataProvider('requestOrders')]
    public function testRequestFallbackAndReset(
        ?string $key,
        ?string $direction,
        string $expectedKey,
        Direction $expectedDirection,
        string $order,
    ): void {
        $plan = $key === null
            ? new StaticSortPlan(self::config(), ['business_order' => null, 'id' => [['identifier' => 'p.position']]])
            : self::plan();
        $data = ScriptedPdoStatement::data([['id' => 1]]);
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(3), ScriptedPdoStatement::count(3), $data]);
        $page = (new PdoPaginator())->paginateWithStaticSort($pdo, self::query(), new PageRequest(99, 2, $key, $direction), $plan, static fn(array $row): array => $row);

        self::assertSame(self::dataSql($order), $pdo->preparedSql[2]);
        self::assertSame([1, $expectedKey, $expectedDirection], [$page->page, $page->sortBy, $page->sortDirection]);
        self::assertSame(0, $data->bindCalls[2]['value']);
    }

    public function testExcludedWhitelistedKeyFailsBeforeCounts(): void
    {
        $pdo = new ScriptedPdo([]);
        $this->expectException(InvalidPaginationQueryException::class);
        try {
            (new PdoPaginator())->paginateWithStaticSort($pdo, self::query(), new PageRequest(sortBy: ' other '), self::plan(), static fn(array $row): array => $row);
        } finally {
            self::assertSame([], $pdo->preparedSql);
        }
    }

    public function testEmptyPageRetainsResolvedSortWithoutDataSql(): void
    {
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(5), ScriptedPdoStatement::count(0)]);
        $page = (new PdoPaginator())->paginateWithStaticSort($pdo, self::query(), new PageRequest(99, 2, 'missing', 'DESC'), self::plan(), static fn(array $row): array => $row);

        self::assertSame(['TOTAL :total_tenant', 'FILTERED :filtered_tenant'], $pdo->preparedSql);
        self::assertSame([[], 1, 5, 0, 0, false, false, 'business_order', Direction::Desc], [
            $page->data,
            $page->page,
            $page->total,
            $page->filtered,
            $page->totalPages,
            $page->hasNext,
            $page->hasPrevious,
            $page->sortBy,
            $page->sortDirection,
        ]);
    }

    public function testMapperFailureWinsOverCursorCleanupFailure(): void
    {
        $failure = new RuntimeException('mapper failed');
        $data = new ScriptedPdoStatement(fetchQueue: [['id' => 1], ['id' => 2]], closeCursorResult: new RuntimeException('cleanup failed'));
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(2), ScriptedPdoStatement::count(2), $data]);

        try {
            (new PdoPaginator())->paginateWithStaticSort($pdo, self::query(), new PageRequest(), self::plan(), static fn(array $row): never => throw $failure);
            self::fail('Expected the mapper throwable.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $data->closeCursorCalls);
        self::assertSame(1, $data->fetchCalls);
    }

    public function testReportedPrepareFailureRetainsExistingException(): void
    {
        $pdo = new ScriptedPdo([false]);
        $this->expectException(PaginationExecutionException::class);
        $this->expectExceptionMessage('Failed to prepare pagination query.');
        try {
            (new PdoPaginator())->paginateWithStaticSort($pdo, self::query(), new PageRequest(), self::plan(), static fn(array $row): array => $row);
        } finally {
            self::assertSame(['TOTAL :total_tenant'], $pdo->preparedSql);
        }
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidProfiles(): iterable
    {
        $valid = ['business_order' => [['identifier' => 'p.position']]];
        yield 'missing default' => [['id' => [['identifier' => 'p.position']]]];
        yield 'no composite profile' => [['business_order' => null]];
        yield 'unknown key' => [$valid + ['unknown' => null]];
        yield 'numeric key' => [$valid + [1 => null]];
        yield 'empty profile' => [$valid + ['other' => []]];
        yield 'non-list profile' => [$valid + ['other' => [2 => ['identifier' => 'p.position']]]];
        yield 'malformed unused profile' => [$valid + ['other' => true]];
        yield 'untyped unused term' => [$valid + ['other' => ['p.position']]];
        yield 'extra field' => [$valid + ['other' => [['identifier' => 'p.position', 'other' => 1]]]];
        yield 'null direction' => [$valid + ['other' => [['identifier' => 'p.position', 'direction' => null]]]];
        yield 'string direction' => [$valid + ['other' => [['identifier' => 'p.position', 'direction' => 'ASC']]]];
        yield 'not a string' => [$valid + ['other' => [['identifier' => 1]]]];
        yield 'four trailing terms' => [['business_order' => [['identifier' => 'a'], ['identifier' => 'b'], ['identifier' => 'c'], ['identifier' => 'd']]]];
        yield 'same path as primary with different casing' => [['business_order' => [['identifier' => 'P.SCOPE_KEY']]]];
        yield 'same path in two trailing terms' => [['business_order' => [['identifier' => 'p.position'], ['identifier' => 'p.POSITION', 'direction' => Direction::Desc]]]];
        foreach (['', 'p.', 'p.a.b.c', 'p.position DESC', 'p.`position`', "p.position\n"] as $identifier) {
            yield 'invalid identifier ' . json_encode($identifier) => [$valid + ['other' => [['identifier' => $identifier]]]];
        }
    }

    /** @param array<mixed> $profiles */
    #[DataProvider('invalidProfiles')]
    public function testConstructionRejectsInvalidProfiles(array $profiles): void
    {
        $this->expectException(InvalidPaginationConfigurationException::class);
        new StaticSortPlan(self::config(), $profiles);
    }

    private static function plan(): StaticSortPlan
    {
        return new StaticSortPlan(self::config(), [
            'business_order' => [['identifier' => 'p.position'], ['identifier' => 'p.id', 'direction' => Direction::Asc]],
            'id' => null,
        ]);
    }

    private static function config(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['business_order' => 'p.scope_key', 'id' => 'p.id', 'other' => 'p.other']), 'business_order', Direction::Asc, 'id', Direction::Asc, 2);
    }

    private static function query(): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor('TOTAL :total_tenant', ['total_tenant' => 1], 'FILTERED :filtered_tenant', ['filtered_tenant' => 2], self::DATA, ['data_tenant' => 2]);
    }

    private static function dataSql(string $order): string
    {
        return self::DATA . "\n" . $order . "\nLIMIT :__pagination_limit\nOFFSET :__pagination_offset";
    }
}
