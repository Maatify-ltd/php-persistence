<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Pagination;

use Maatify\PhpPersistence\Pagination\Criteria\KeysetQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\KeysetPageResultDTO;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use stdClass;

final class KeysetContractTest extends TestCase
{
    public function testDescriptorAcceptsTrustedPredicatesWithOrdinaryColonLiterals(): void
    {
        $descriptor = new KeysetQueryDescriptor(
            'catalog',
            '1',
            'entries',
            ['id', 'name'],
            't.tenant_id = :tenant',
            ['tenant'],
            "t.name <> ':ordinary'",
        );

        self::assertInstanceOf(KeysetQueryDescriptor::class, $descriptor);
    }

    public function testRegisteredIdentifiersAreDetachedFromCallerReferences(): void
    {
        $column = 'id';
        $parameter = 'tenant';
        $descriptor = new KeysetQueryDescriptor('catalog', '1', 'entries', [&$column], 't.tenant_id = :tenant', [&$parameter]);

        $column = '__ks_id';
        $parameter = '__ks_limit';

        self::assertSame(['id'], $descriptor->registration()['columns']);
        self::assertSame(['tenant'], $descriptor->registration()['parameterNames']);
    }

    /** @param list<string> $columns
     *  @param array<array-key, mixed> $parameterNames
     */
    #[DataProvider('invalidRegistration')]
    public function testDescriptorRejectsInvalidRegistration(
        array $columns,
        array $parameterNames,
        string $scopePredicate = 't.tenant_id = :tenant',
        string $filterPredicate = '1 = 1',
    ): void {
        $this->expectException(InvalidPaginationQueryException::class);

        // Exercise the public constructor with input from an untyped configuration source.
        // @phpstan-ignore argument.type
        new KeysetQueryDescriptor('catalog', '1', 'entries', $columns, $scopePredicate, $parameterNames, $filterPredicate);
    }

    /** @return iterable<string, array{list<string>, array<array-key, mixed>, 2?: string, 3?: string}> */
    public static function invalidRegistration(): iterable
    {
        yield 'no projection' => [[], ['tenant']];
        yield 'duplicate projection' => [['id', 'id'], ['tenant']];
        yield 'folded duplicate projection' => [['id', 'ID'], ['tenant']];
        yield 'reserved projected name' => [['id', '__KS_ID'], ['tenant']];
        yield 'identifier ending in LF' => [["id\n"], ['tenant']];
        yield 'expression in projection' => [['id AS alias'], ['tenant']];
        yield 'duplicate name' => [['id'], ['tenant', 'tenant']];
        yield 'non-list names' => [['id'], [1 => 'tenant']];
        yield 'non-string name' => [['id'], [1]];
        yield 'colon in declared name' => [['id'], [':tenant']];
        yield 'declared name ends in LF' => [['id'], ["tenant\n"]];
        yield 'reserved name' => [['id'], ['__KS_Limit']];
        yield 'reserved raw scope' => [['id'], ['tenant'], 't.tenant_id = :__KS_after'];
        yield 'reserved raw filter comment' => [['id'], ['tenant'], 't.tenant_id = :tenant', '1 = 1 /* :__ks_limit */'];
        yield 'reserved raw quoted string' => [['id'], ['tenant'], "t.name = ':__kS_after'"];
    }

    public function testValidResultsPreserveJsonAndOrder(): void
    {
        $object = new stdClass();
        $object->name = 'second';
        $page = new KeysetPageResultDTO([['name' => 'first'], $object], true, 'opaque');
        self::assertSame([['name' => 'first'], $object], $page->data);
        self::assertSame('{"data":[{"name":"first"},{"name":"second"}],"hasNext":true,"nextCursor":"opaque"}', json_encode($page, JSON_THROW_ON_ERROR));
        self::assertSame('{"data":[],"hasNext":false,"nextCursor":null}', json_encode(new KeysetPageResultDTO([], false, null), JSON_THROW_ON_ERROR));
    }

    /** @param array<array-key, mixed> $data */
    #[DataProvider('invalidResult')]
    public function testDirectConstructionRejectsInvalidResult(array $data, bool $hasNext, ?string $cursor): void
    {
        $this->expectException(PaginationExecutionException::class);

        // @phpstan-ignore argument.type
        new KeysetPageResultDTO($data, $hasNext, $cursor);
    }

    /** @param list<mixed> $data */
    #[DataProvider('invalidResultWithCursor')]
    public function testDirectConstructionRedactsCursorInExceptionTrace(array $data, bool $hasNext): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        self::assertIsString($previous);
        ini_set('zend.exception_ignore_args', '0');

        try {
            // @phpstan-ignore argument.type (Exercise validation before a malformed result can be returned.)
            new KeysetPageResultDTO($data, $hasNext, 'synthetic-keyset-cursor');
            self::fail('Expected invalid construction to throw.');
        } catch (PaginationExecutionException $failure) {
            foreach ($failure->getTrace() as $frame) {
                if (($frame['class'] ?? null) === KeysetPageResultDTO::class && $frame['function'] === '__construct') {
                    self::assertInstanceOf(SensitiveParameterValue::class, $frame['args'][2] ?? null);

                    return;
                }
            }
            self::fail('The constructor frame was not captured.');
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /** @return iterable<string, array{list<mixed>, bool}> */
    public static function invalidResultWithCursor(): iterable
    {
        yield 'invalid data' => [[10], true];
        yield 'inconsistent navigation' => [[['id' => 10]], false];
    }

    /** @return iterable<string, array{array<array-key, mixed>, bool, ?string}> */
    public static function invalidResult(): iterable
    {
        yield 'non-list' => [[2 => ['id' => 10]], false, null];
        yield 'scalar item' => [[10], false, null];
        yield 'missing cursor' => [[['id' => 10]], true, null];
        yield 'unexpected cursor' => [[['id' => 10]], false, 'opaque'];
        yield 'empty cursor' => [[['id' => 10]], true, ''];
        yield 'empty page with cursor' => [[], true, 'opaque'];
        yield 'empty page with no cursor but hasNext' => [[], true, null];
    }
}
