<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Unit\Ordering\Repository;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedPdo;
use Maatify\PhpPersistence\Tests\Support\Ordering\OrderingScriptedStatement;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopedOrderingManagerTimestampTest extends TestCase
{
    /** @return iterable<string, array{non-empty-string|null, ?string}> */
    public static function invalidTimestamps(): iterable
    {
        yield 'value without a configured column' => [null, '2024-02-29 00:00:00'];
        yield 'missing value' => ['updated_at', null];
        yield 'empty value' => ['updated_at', ''];
        yield 'zero year' => ['updated_at', '0000-01-01 00:00:00'];
        yield 'invalid calendar day' => ['updated_at', '2025-02-29 00:00:00'];
        yield 'invalid calendar month' => ['updated_at', '2026-13-01 00:00:00'];
        yield 'invalid clock hour' => ['updated_at', '2026-01-01 24:00:00'];
        yield 'invalid clock minute' => ['updated_at', '2026-01-01 23:60:00'];
        yield 'invalid clock second' => ['updated_at', '2026-01-01 23:59:60'];
        yield 'empty fraction' => ['updated_at', '2026-01-01 00:00:00.'];
        yield 'more than six fractional digits' => ['updated_at', '2026-01-01 00:00:00.1234567'];
        yield 'date only' => ['updated_at', '2026-01-01'];
        yield 'relative expression' => ['updated_at', 'tomorrow'];
        yield 'ISO separator' => ['updated_at', '2026-01-01T00:00:00'];
        yield 'zone offset' => ['updated_at', '2026-01-01 00:00:00+00:00'];
        yield 'exponent' => ['updated_at', '2026-01-01 00:00:00.1e2'];
        yield 'trailing newline' => ['updated_at', "2026-01-01 00:00:00\n"];
    }

    /** @param non-empty-string|null $column */
    #[DataProvider('invalidTimestamps')]
    public function testInvalidTimestampIsRejectedBeforeUsingPdo(?string $column, ?string $value): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('inTransaction');
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('prepare');
        $pdo->expects(self::never())->method('exec');

        $this->expectException(InvalidOrderingOperationException::class);
        (new ScopedOrderingManager())->moveWithinScope(
            $pdo,
            new ScopedOrderingConfig(table: 'items', updatedAtColumn: $column),
            null,
            5,
            2,
            $value,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function validTimestamps(): iterable
    {
        yield 'midnight on a leap day' => ['2024-02-29 00:00:00'];
        yield 'one fractional digit' => ['2026-01-01 00:00:00.1'];
        yield 'six fractional digits and upper clock boundary' => ['2026-12-31 23:59:59.012300'];
    }

    #[DataProvider('validTimestamps')]
    public function testValidTimestampReachesTargetUpdateWithoutNormalization(string $value): void
    {
        $target = new OrderingScriptedStatement(rowCount: 1);
        $pdo = new OrderingScriptedPdo([
            new OrderingScriptedStatement(fetchColumnQueue: [3, false]),
            new OrderingScriptedStatement(fetchColumnQueue: [3]),
            new OrderingScriptedStatement(),
            $target,
        ]);

        self::assertTrue((new ScopedOrderingManager())->moveWithinScope(
            $pdo,
            new ScopedOrderingConfig(table: 'items', updatedAtColumn: 'updated_at'),
            null,
            5,
            2,
            $value,
        ));
        self::assertSame($value, $target->executedWith[0]['updated_at_value']);
    }
}
