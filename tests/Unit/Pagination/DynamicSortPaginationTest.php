<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Pagination;

use JsonSerializable;
use Maatify\PhpPersistence\Pagination\Config\PaginationConfig;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\Criteria\PdoPaginationQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\MultiSortPageResultDTO;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Enum\SortDirectionEnum as Direction;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use Maatify\PhpPersistence\Pagination\Repository\PdoPaginator;
use Maatify\PhpPersistence\Pagination\ValueObject\ResolvedDynamicSort;
use Maatify\PhpPersistence\Pagination\ValueObject\SortWhitelist;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Pagination\ScriptedPdoStatement;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class DynamicSortPaginationTest extends TestCase
{
    private const string DATA_SQL = "SELECT o.id FROM orders o WHERE o.tenant_id = :tenant AND o.score >= :score";

    public function testApprovedSignatureAndReadOnlyResult(): void
    {
        $method = new ReflectionMethod(PdoPaginator::class, 'paginateWithDynamicSort');
        self::assertTrue($method->isPublic());
        self::assertSame(['pdo', 'query', 'request', 'config', 'sorts', 'mapper', 'allowTieBreakerSelection'], array_map(static fn($parameter): string => $parameter->name, $method->getParameters()));
        self::assertSame([PDO::class, PdoPaginationQueryDescriptor::class, PageRequest::class, PaginationConfig::class, 'array', 'callable', 'bool'], array_map(static fn($parameter): string => (string) $parameter->getType(), $method->getParameters()));
        self::assertSame(6, $method->getNumberOfRequiredParameters());
        self::assertFalse($method->getParameters()[6]->getDefaultValue());
        self::assertSame(MultiSortPageResultDTO::class, (string) $method->getReturnType());

        $result = new ReflectionClass(MultiSortPageResultDTO::class);
        self::assertTrue($result->isFinal());
        self::assertTrue($result->isReadOnly());
        self::assertTrue($result->implementsInterface(JsonSerializable::class));
        self::assertNull($result->getParentClass() ?: null);
        $constructor = $result->getConstructor();
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame(PageResultDTO::class, (string) $result->getProperty('page')->getType());
        self::assertSame('array', (string) $result->getProperty('selectedSorts')->getType());
        self::assertSame('array', (string) $result->getProperty('effectiveSorts')->getType());
    }

    /** @return iterable<string, array{list<array{key: string, direction: string}>, bool, string, list<array{key: string|null, direction: string}>}> */
    public static function successfulOrders(): iterable
    {
        yield 'empty request' => [[], false, '`o`.`status` DESC, `o`.`id` ASC', [self::sort('status', 'DESC'), self::sort(null, 'ASC')]];
        yield 'one term, hidden appended tie even when selection enabled' => [
            [self::sort('score', 'aSc')], true, '`o`.`score` ASC, `o`.`id` ASC', [self::sort('score', 'ASC'), self::sort(null, 'ASC')],
        ];
        yield 'two mixed terms' => [
            [self::sort('score', 'desc'), self::sort('status', 'ASC')], false, '`o`.`score` DESC, `o`.`status` ASC, `o`.`id` ASC',
            [self::sort('score', 'DESC'), self::sort('status', 'ASC'), self::sort(null, 'ASC')],
        ];
        yield 'three reversed priority and mixed terms' => [
            [self::sort('name', 'desc'), self::sort('status', 'asc'), self::sort('score', 'desc')], false,
            '`o`.`name` DESC, `o`.`status` ASC, `o`.`score` DESC, `o`.`id` ASC',
            [self::sort('name', 'DESC'), self::sort('status', 'ASC'), self::sort('score', 'DESC'), self::sort(null, 'ASC')],
        ];
        yield 'canonical tie selected alone' => [[self::sort('internal_id', 'dEsC')], true, '`o`.`id` DESC', [self::sort('internal_id', 'DESC')]];
        yield 'canonical tie selected last' => [
            [self::sort('score', 'desc'), self::sort('internal_id', 'ASC')], true, '`o`.`score` DESC, `o`.`id` ASC',
            [self::sort('score', 'DESC'), self::sort('internal_id', 'ASC')],
        ];
        yield 'case-folded column alias to tie allowed last under default policy' => [
            [self::sort('status', 'asc'), self::sort('alternate_id', 'desc')], false, '`o`.`status` ASC, `o`.`ID` DESC',
            [self::sort('status', 'ASC'), self::sort('alternate_id', 'DESC')],
        ];
        yield 'case-folded column alias to tie selected alone with policy enabled' => [
            [self::sort('alternate_id', 'desc')], true, '`o`.`ID` DESC', [self::sort('alternate_id', 'DESC')],
        ];
    }

    /**
     * @param list<array{key: string, direction: string}> $sorts
     * @param list<array{key: string|null, direction: string}> $effective
     */
    #[DataProvider('successfulOrders')]
    public function testGoldenSqlBindingsAndPublicMetadata(array $sorts, bool $allowTie, string $order, array $effective): void
    {
        $total = ScriptedPdoStatement::count(10);
        $filtered = ScriptedPdoStatement::count(5);
        $data = ScriptedPdoStatement::data([['id' => 9]]);
        $pdo = new ScriptedPdo([$total, $filtered, $data]);

        $result = (new PdoPaginator())->paginateWithDynamicSort(
            pdo: $pdo,
            query: self::query(),
            request: new PageRequest(page: '+2', perPage: '+2'),
            config: self::config(),
            sorts: $sorts,
            mapper: static fn(array $row): object => (object) $row,
            allowTieBreakerSelection: $allowTie,
        );

        self::assertSame(['TOTAL :total_tenant', 'FILTERED :filtered_tenant', self::dataSql($order)], $pdo->preparedSql);
        self::assertSame([['parameter' => ':total_tenant', 'value' => 1, 'type' => PDO::PARAM_INT]], $total->bindCalls);
        self::assertSame([['parameter' => ':filtered_tenant', 'value' => 'scoped', 'type' => PDO::PARAM_STR]], $filtered->bindCalls);
        self::assertSame([
            ['parameter' => ':tenant', 'value' => 1, 'type' => PDO::PARAM_INT],
            ['parameter' => ':score', 'value' => -5, 'type' => PDO::PARAM_INT],
            ['parameter' => ':__pagination_limit', 'value' => 2, 'type' => PDO::PARAM_INT],
            ['parameter' => ':__pagination_offset', 'value' => 2, 'type' => PDO::PARAM_INT],
        ], $data->bindCalls);
        $selected = array_slice($effective, 0, count($sorts));
        self::assertSame($selected, array_map(self::formatTerm(...), $result->selectedSorts));
        self::assertSame($effective, array_map(self::formatTerm(...), $result->effectiveSorts));
        self::assertSame($effective[0]['key'], $result->page->sortBy);
        self::assertSame(Direction::from($effective[0]['direction']), $result->page->sortDirection);
        self::assertSame(9, $result->page->data[0]->id);
        $array = $result->page->toArray();
        $array['pagination']['selected_sorts'] = $selected;
        $array['pagination']['effective_sorts'] = $effective;
        self::assertSame($array, $result->toArray());
        self::assertSame(json_encode($array, JSON_THROW_ON_ERROR), json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame([0, 0, 0, []], [$pdo->beginTransactionCalls, $pdo->commitCalls, $pdo->rollBackCalls, $pdo->setAttributeCalls]);
    }

    public function testLegacyGoldenAliasWorksInDefaultAndExplicitDynamicSort(): void
    {
        foreach ([
            [new SortWhitelist(['created_at' => 'id', 'id' => 'id']), 'created_at', []],
            [new SortWhitelist(['created_at' => 'id', 'id' => 'id']), 'created_at', [self::sort('created_at', 'DESC')]],
            [new SortWhitelist(['id' => 'id']), 'id', []],
            [new SortWhitelist(['recent' => 'o.ID', 'id' => 'o.id']), 'recent', []],
        ] as [$whitelist, $key, $terms]) {
            $config = new PaginationConfig($whitelist, $key, Direction::Desc, 'id', Direction::Asc);
            $pdo = new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), ScriptedPdoStatement::data([['id' => 7]])]);
            $result = (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), new PageRequest(), $config, $terms, self::row(...));
            $identifier = $key === 'recent' ? '`o`.`ID`' : '`id`';
            self::assertSame(self::dataSql("$identifier DESC"), $pdo->preparedSql[2]);
            self::assertSame($terms, $result->toArray()['pagination']['selected_sorts']);
            self::assertSame([self::sort($key, 'DESC')], $result->toArray()['pagination']['effective_sorts']);
            self::assertSame($key, $result->page->sortBy);
        }
    }

    public function testLexicallyDifferentQualifierPathsStayDistinct(): void
    {
        $config = new PaginationConfig(new SortWhitelist(['plain' => 'id', 'tie' => 'a.id', 'joined' => 'b.id']), 'plain', Direction::Desc, 'tie', Direction::Asc);
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), ScriptedPdoStatement::data([['id' => 7]])]);
        (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), new PageRequest(), $config, [self::sort('plain', 'DESC'), self::sort('joined', 'ASC')], self::row(...));
        self::assertSame(self::dataSql('`id` DESC, `b`.`id` ASC, `a`.`id` ASC'), $pdo->preparedSql[2]);
    }

    public function testEmptyAndOvershootRetainResolvedSortAndHideTheTie(): void
    {
        $terms = [self::sort('score', 'desc'), self::sort('status', 'asc')];
        $config = self::config();
        $paginator = new PdoPaginator();
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(5), ScriptedPdoStatement::count(0)]);
        $empty = $paginator->paginateWithDynamicSort($pdo, self::query(), new PageRequest(PHP_INT_MAX, PHP_INT_MAX), $config, $terms, self::row(...));

        self::assertSame(['TOTAL :total_tenant', 'FILTERED :filtered_tenant'], $pdo->preparedSql);
        self::assertSame([
            'data' => [], 'pagination' => [
                'page' => 1, 'per_page' => 200, 'total' => 5, 'filtered' => 0, 'total_pages' => 0,
                'has_next' => false, 'has_previous' => false, 'sort_by' => 'score', 'sort_direction' => 'DESC',
                'selected_sorts' => [self::sort('score', 'DESC'), self::sort('status', 'ASC')],
                'effective_sorts' => [self::sort('score', 'DESC'), self::sort('status', 'ASC'), self::sort(null, 'ASC')],
            ],
        ], $empty->toArray());

        $data = ScriptedPdoStatement::data([['id' => 1]]);
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(3), ScriptedPdoStatement::count(3), $data]);
        $overshoot = $paginator->paginateWithDynamicSort($pdo, self::query(), new PageRequest('99', 2), $config, $terms, self::row(...));
        self::assertSame(1, $overshoot->page->page);
        self::assertSame(0, $data->bindCalls[3]['value']);
        self::assertSame($empty->effectiveSorts, $overshoot->effectiveSorts);

        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(0), ScriptedPdoStatement::count(0)]);
        $default = $paginator->paginateWithDynamicSort($pdo, self::query(), new PageRequest(), $config, [], self::row(...));
        self::assertSame([], $default->selectedSorts);
        self::assertSame([self::sort('status', 'DESC'), self::sort(null, 'ASC')], $default->toArray()['pagination']['effective_sorts']);
    }

    /** @return iterable<string, array{array<mixed>, PageRequest, bool}> */
    public static function rejectedRequests(): iterable
    {
        $request = new PageRequest();
        $valid = self::sort('status', 'ASC');
        yield 'non-list' => [[1 => $valid], $request, false];
        yield 'four terms' => [array_fill(0, 4, $valid), $request, false];
        yield 'scalar term' => [['score'], $request, false];
        yield 'missing key' => [[['direction' => 'ASC']], $request, false];
        yield 'extra field' => [[$valid + ['extra' => 'drop']], $request, false];
        yield 'numeric key' => [[['key' => 3, 'direction' => 'ASC']], $request, false];
        yield 'enum direction' => [[['key' => 'status', 'direction' => Direction::Asc]], $request, false];
        yield 'invalid direction' => [[self::sort('status', 'ascending')], $request, false];
        yield 'leading direction whitespace' => [[self::sort('status', ' ASC')], $request, false];
        yield 'direction trailing LF' => [[self::sort('status', "DESC\n")], $request, false];
        yield 'non-ASCII direction' => [[self::sort('status', 'ＡＳＣ')], $request, false];
        yield 'key outside whitelist' => [[self::sort('unknown', 'ASC')], $request, false];
        yield 'key spelling case is significant' => [[self::sort('STATUS', 'ASC')], $request, false];
        yield 'key padding' => [[self::sort(' status', 'ASC')], $request, false];
        yield 'key trailing LF' => [[self::sort("status\n", 'ASC')], $request, false];
        yield 'SQL fragment as key' => [[self::sort('o.id DESC; SELECT 1', 'ASC')], $request, false];
        yield 'duplicate key' => [[$valid, $valid], $request, false];
        yield 'duplicate path with column case alias' => [[$valid, self::sort('state_alias', 'ASC')], $request, false];
        yield 'canonical tie reserved' => [[self::sort('internal_id', 'ASC')], $request, false];
        yield 'canonical tie before end' => [[self::sort('internal_id', 'ASC'), $valid], $request, true];
        yield 'alias to tie before end' => [[self::sort('alternate_id', 'DESC'), $valid], $request, false];
        yield 'two aliases of tie' => [[self::sort('alternate_id', 'DESC'), self::sort('internal_id', 'ASC')], $request, true];
        yield 'mixed legacy sort with empty list' => [[], new PageRequest(sortBy: ''), false];
        yield 'mixed legacy direction' => [[$valid], new PageRequest(sortDirection: 'ASC'), false];
    }

    /** @param array<mixed> $sorts */
    #[DataProvider('rejectedRequests')]
    public function testRejectsInvalidRequestBeforePreparingAnything(array $sorts, PageRequest $request, bool $allowTie): void
    {
        $pdo = new ScriptedPdo([]);
        $this->expectException(InvalidPaginationQueryException::class);
        try {
            (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), $request, self::config(), $sorts, self::row(...), $allowTie);
        } finally {
            self::assertSame([], $pdo->preparedSql);
        }
    }

    public function testQualifierCaseConflictAndConfigurationPrecedenceBeforeSql(): void
    {
        $pdo = new ScriptedPdo([]);
        $config = new PaginationConfig(new SortWhitelist(['first' => 'O.iD', 'tie' => 'o.id']), 'first', Direction::Desc, 'tie', Direction::Asc);
        $this->expectException(InvalidPaginationConfigurationException::class);
        try {
            (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), new PageRequest(sortBy: 'conflict'), $config, [null], self::row(...));
        } finally {
            self::assertSame([], $pdo->preparedSql);
        }
    }

    #[DataProvider('qualifierConflicts')]
    public function testRequestedQualifierConflictFailsBeforeSql(bool $tieConflict): void
    {
        $pdo = new ScriptedPdo([]);
        $config = new PaginationConfig(new SortWhitelist(['a' => 'db.O.Score', 'b' => 'db.o.score', 't' => $tieConflict ? 'db.o.score' : 'db.o.id', 'default' => 'db.o.date']), 'default', Direction::Desc, 't', Direction::Asc);
        $sorts = [self::sort('a', 'ASC')];
        if (! $tieConflict) {
            $sorts[] = self::sort('b', 'ASC');
        }
        $this->expectException(InvalidPaginationConfigurationException::class);
        try {
            (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), new PageRequest(), $config, $sorts, self::row(...));
        } finally {
            self::assertSame([], $pdo->preparedSql);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function qualifierConflicts(): iterable
    {
        yield 'selected with tie path' => [true];
        yield 'selected pair' => [false];
    }

    /** @param list<string> $keys */
    #[DataProvider('qualifierConflictOrders')]
    public function testQualifierConflictTakesPrecedenceOverTiePosition(array $keys): void
    {
        $pdo = new ScriptedPdo([]);
        $config = new PaginationConfig(
            new SortWhitelist(['name' => 'o.name', 'tie' => 'o.id', 'alias' => 'O.id']),
            'name',
            Direction::Asc,
            'tie',
            Direction::Asc,
        );
        $sorts = array_map(static fn(string $key): array => self::sort($key, 'ASC'), $keys);

        $this->expectException(InvalidPaginationConfigurationException::class);
        try {
            (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), new PageRequest(), $config, $sorts, self::row(...), allowTieBreakerSelection: true);
        } finally {
            self::assertSame([], $pdo->preparedSql);
        }
    }

    /** @return iterable<string, array{list<string>}> */
    public static function qualifierConflictOrders(): iterable
    {
        yield 'canonical tie first' => [['tie', 'alias']];
        yield 'conflicting alias first' => [['alias', 'tie']];
    }

    public function testDtoFactoryRejectsUnrelatedPageBeforePublishingMetadata(): void
    {
        $sort = ResolvedDynamicSort::resolve(self::config(), new PageRequest(), [self::sort('score', 'ASC')], false);
        foreach ([['status', Direction::Asc], ['score', Direction::Desc]] as [$key, $direction]) {
            try {
                MultiSortPageResultDTO::fromResolved(new PageResultDTO([], 1, 2, 0, 0, 0, false, false, $key, $direction), $sort);
                self::fail('Expected an inconsistent result to be rejected.');
            } catch (PaginationExecutionException $failure) {
                self::assertStringContainsString('sort', $failure->getMessage());
            }
        }
    }

    /** @return iterable<string, array{class-string}> */
    public static function guardedTypes(): iterable
    {
        yield 'public wrapper' => [MultiSortPageResultDTO::class];
        yield 'internal order' => [ResolvedDynamicSort::class];
    }

    /** @param class-string $class */
    #[DataProvider('guardedTypes')]
    public function testNativeHydrationCannotBypassValidation(string $class): void
    {
        self::assertTrue(class_exists($class));
        $this->expectException(PaginationExecutionException::class);
        unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class), ['allowed_classes' => [$class]]);
    }

    /** @return iterable<string, array{list<ScriptedPdoStatement|false>, string}> */
    public static function reportedFailures(): iterable
    {
        yield 'prepare' => [[false], 'prepare'];
        yield 'bind' => [[new ScriptedPdoStatement(bindResults: [':total_tenant' => false])], 'bind'];
        yield 'execute' => [[ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), new ScriptedPdoStatement(executeResult: false)], 'execute'];
        yield 'fetch' => [[ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), new ScriptedPdoStatement(fetchQueue: [false], statementErrorCode: 'HY000')], 'fetch'];
    }

    /** @param list<ScriptedPdoStatement|false> $statements */
    #[DataProvider('reportedFailures')]
    public function testDriverReportedFailuresDoNotProduceResults(array $statements, string $operation): void
    {
        $pdo = new ScriptedPdo($statements);
        $this->expectException(PaginationExecutionException::class);
        $this->expectExceptionMessage($operation);
        (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), new PageRequest(), self::config(), [], self::row(...));
    }

    #[DataProvider('throwableSources')]
    public function testOriginalThrowableWinsOverCursorCleanup(bool $pdoThrows): void
    {
        $failure = $pdoThrows ? new PDOException('fetch failed') : new RuntimeException('mapper failed');
        $data = new ScriptedPdoStatement(fetchQueue: [$pdoThrows ? $failure : ['id' => 3]], closeCursorResult: new RuntimeException('cleanup failed'));
        $pdo = new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $data]);
        try {
            (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), new PageRequest(), self::config(), [], static fn(array $row): never => throw $failure);
            self::fail('Expected pagination to propagate the original throwable.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(1, $data->closeCursorCalls);
    }

    #[DataProvider('earlyDriverStages')]
    public function testEarlyDriverThrowableRetainsIdentity(string $stage): void
    {
        $failure = new PDOException($stage . ' failed');
        $statement = match ($stage) {
            'prepare' => $failure,
            'bind' => new ScriptedPdoStatement(bindResults: [':total_tenant' => $failure]),
            'execute' => new ScriptedPdoStatement(executeResult: $failure),
            default => throw new RuntimeException('Unexpected driver stage in the fixture.'),
        };
        $pdo = new ScriptedPdo([$statement]);

        try {
            (new PdoPaginator())->paginateWithDynamicSort($pdo, self::query(), new PageRequest(), self::config(), [], self::row(...));
            self::fail('Expected the original driver exception.');
        } catch (PDOException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(['TOTAL :total_tenant'], $pdo->preparedSql);
    }

    /** @return iterable<string, array{string}> */
    public static function earlyDriverStages(): iterable
    {
        foreach (['prepare', 'bind', 'execute'] as $stage) {
            yield $stage => [$stage];
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function throwableSources(): iterable
    {
        yield 'PDO fetch' => [true];
        yield 'mapper' => [false];
    }

    private static function config(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist([
            'status' => 'o.status', 'state_alias' => 'o.STATUS', 'score' => 'o.score', 'name' => 'o.name',
            'internal_id' => 'o.id', 'alternate_id' => 'o.ID',
        ]), 'status', Direction::Desc, 'internal_id', Direction::Asc, 2);
    }

    private static function query(): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor('TOTAL :total_tenant', ['total_tenant' => 1], 'FILTERED :filtered_tenant', ['filtered_tenant' => 'scoped'], self::DATA_SQL . "\n", ['tenant' => 1, 'score' => -5]);
    }

    private static function dataSql(string $order): string
    {
        return self::DATA_SQL . "\nORDER BY " . $order . "\nLIMIT :__pagination_limit\nOFFSET :__pagination_offset";
    }

    /**
     * @template T of string|null
     * @param T $key
     * @return array{key: T, direction: string}
     */
    private static function sort(?string $key, string $direction): array
    {
        return ['key' => $key, 'direction' => $direction];
    }

    /**
     * @param array{key: string|null, direction: Direction} $term
     * @return array{key: string|null, direction: string}
     */
    private static function formatTerm(array $term): array
    {
        return self::sort($term['key'], $term['direction']->value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        return $row;
    }
}
