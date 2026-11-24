<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Ordering;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UniqueOrderingConfigTest extends TestCase
{
    /** @return iterable<string, array{ScopedOrderingConfig, string, string, ?int, int, int}> */
    public static function ineligibleProfiles(): iterable
    {
        $global = new ScopedOrderingConfig('app.items', deletedAtColumn: null);
        $scoped = new ScopedOrderingConfig('app.items', scopeColumn: 'scope_id', deletedAtColumn: null);
        yield 'unqualified items' => [new ScopedOrderingConfig('items', deletedAtColumn: null), 'app.anchor', 'id', 1, 20, 10];
        yield 'unqualified anchor' => [$global, 'anchor', 'id', 1, 20, 10];
        yield 'untrusted table path' => [$global, 'app.anchor;DROP', 'id', 1, 20, 10];
        yield 'untrusted primary key' => [$global, 'app.anchor', 'id DESC', 1, 20, 10];
        yield 'same table' => [$global, 'app.items', 'id', 1, 20, 10];
        yield 'missing global anchor' => [$global, 'app.anchor', 'id', null, 20, 10];
        yield 'nonpositive global anchor' => [$global, 'app.anchor', 'id', 0, 20, 10];
        yield 'scoped anchor is dynamic' => [$scoped, 'app.anchor', 'id', 1, 20, 10];
        yield 'position bound zero' => [$global, 'app.anchor', 'id', 1, 0, 1];
        if (PHP_INT_SIZE >= 8) {
            yield 'position bound overflows the SQL profile' => [$global, 'app.anchor', 'id', 1, PHP_INT_MAX, 1];
        }
        yield 'row bound zero' => [$global, 'app.anchor', 'id', 1, 20, 0];
        yield 'row bound exceeds position limit' => [$global, 'app.anchor', 'id', 1, 20, 21];
        yield 'soft delete' => [new ScopedOrderingConfig('app.items'), 'app.anchor', 'id', 1, 20, 10];
        yield 'updated timestamp' => [
            new ScopedOrderingConfig('app.items', deletedAtColumn: null, updatedAtColumn: 'updated_at'), 'app.anchor', 'id', 1, 20, 10,
        ];
        yield 'nullable scope' => [
            new ScopedOrderingConfig('app.items', scopeColumn: 'scope_id', deletedAtColumn: null, nullableScope: true),
            'app.anchor', 'id', null, 20, 10,
        ];
        yield 'column roles overlap' => [
            new ScopedOrderingConfig('app.items', scopeColumn: 'ID', deletedAtColumn: null), 'app.anchor', 'id', null, 20, 10,
        ];
    }

    #[DataProvider('ineligibleProfiles')]
    public function testInvalidProfilesFailDuringConfiguration(
        ScopedOrderingConfig $ordering,
        string $anchor,
        string $anchorColumn,
        ?int $globalId,
        int $positions,
        int $rows,
    ): void {
        $this->expectException(InvalidOrderingConfigurationException::class);
        new UniqueOrderingConfig($ordering, $anchor, $anchorColumn, $globalId, $positions, $rows, [], 'revision-1');
    }
}
