<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Integration\Ordering;

use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B-01 and B-07: getNextPosition() and rowExistsInScope(), ported from legacy
 * tests/Integration/Pdo/Ordering/ScopedOrderingManagerReadOperationsTest.php (1f6a7aa).
 */
final class ScopedOrderingManagerReadOperationsTest extends OrderingIntegrationTestCase
{
    private ScopedOrderingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ScopedOrderingManager();
    }

    #[DataProvider('nonThrowingErrorModes')]
    public function testOrderingOperationsPreserveHostPdoErrorMode(int $mode): void
    {
        $pdo = $this->pdo();
        self::assertTrue($pdo->setAttribute(PDO::ATTR_ERRMODE, $mode));
        $config = $this->globalConfig();
        self::assertSame(1, $this->manager->getNextPosition($pdo, $config));
        self::assertFalse($this->manager->rowExistsInScope($pdo, $config, null, 1));

        $pdo->beginTransaction();
        try {
            self::assertSame(1, $this->manager->getNextPositionForUpdate($pdo, $config));
            $first = $this->fixture->insertGlobal(1);
            $second = $this->fixture->insertGlobal(2);
            self::assertTrue($this->manager->rowExistsInScope($pdo, $config, null, $second));
            self::assertSame(3, $this->manager->getNextPositionForUpdate($pdo, $config));
            self::assertTrue($this->manager->moveWithinScope($pdo, $config, null, $second, 1));
            self::assertSame([$first => 2, $second => 1], $this->reader()->globalOrdersById());
            self::assertSame($mode, $pdo->getAttribute(PDO::ATTR_ERRMODE));
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }

    /** @return iterable<string, array{int}> */
    public static function nonThrowingErrorModes(): iterable
    {
        yield 'silent' => [PDO::ERRMODE_SILENT];
        yield 'warning' => [PDO::ERRMODE_WARNING];
    }

    public function testGetNextPositionReturnsOneForEmptyGlobalTable(): void
    {
        self::assertSame(1, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
    }

    public function testGetNextPositionReturnsMaxPlusOneForContiguousGlobalRows(): void
    {
        $this->fixture->insertGlobal(1);
        $this->fixture->insertGlobal(2);
        $this->fixture->insertGlobal(3);

        self::assertSame(4, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
    }

    public function testGetNextPositionUsesMaximumPositionInsteadOfFirstGapForGlobalRows(): void
    {
        $this->fixture->insertGlobal(1);
        $this->fixture->insertGlobal(3);

        self::assertSame(4, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
    }

    public function testGetNextPositionIgnoresSoftDeletedGlobalMaximumWhenSoftDeleteColumnIsConfigured(): void
    {
        $this->fixture->insertGlobal(2);
        $this->fixture->insertGlobal(9, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
    }

    public function testGetNextPositionIncludesSoftDeletedGlobalMaximumWhenSoftDeleteFilteringIsDisabled(): void
    {
        $this->fixture->insertGlobal(2);
        $this->fixture->insertGlobal(9, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(10, $this->manager->getNextPosition($this->pdo(), $this->globalConfigWithoutSoftDeletes()));
    }

    public function testGetNextPositionReturnsOneForEmptyRequestedScope(): void
    {
        $this->fixture->insertScoped('other-scope', 6);

        self::assertSame(1, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'requested-scope'));
    }

    public function testGetNextPositionMaintainsIndependentMaximumsForScopes(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 4);
        $this->fixture->insertScoped('scope-b', 8);

        self::assertSame(5, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(9, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-b'));
    }

    public function testGetNextPositionAcceptsIntegerScopeValueThroughPreparedStatement(): void
    {
        $this->fixture->insertScoped(42, 7);
        $this->fixture->insertScoped(43, 11);

        self::assertSame(8, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 42));
    }

    public function testGetNextPositionAcceptsStringScopeValueThroughPreparedStatement(): void
    {
        $this->fixture->insertScoped('alpha', 3);
        $this->fixture->insertScoped('beta', 10);

        self::assertSame(4, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'alpha'));
    }

    public function testGetNextPositionIgnoresSoftDeletedRowsOnlyInsideRequestedScope(): void
    {
        $this->fixture->insertScoped('scope-a', 2);
        $this->fixture->insertScoped('scope-a', 9, deletedAt: '2026-01-01 00:00:00');
        $this->fixture->insertScoped('scope-b', 12, deletedAt: '2026-01-01 00:00:00');
        $this->fixture->insertScoped('scope-b', 4);

        self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(5, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-b'));
    }

    public function testGetNextPositionRejectsScopedConfigurationWithoutScopeValue(): void
    {
        $this->expectException(InvalidOrderingOperationException::class);

        $this->manager->getNextPosition($this->pdo(), $this->scopedConfig());
    }

    public function testGetNextPositionRejectsGlobalConfigurationWithScopeValue(): void
    {
        $this->expectException(InvalidOrderingOperationException::class);

        $this->manager->getNextPosition($this->pdo(), $this->globalConfig(), 'unexpected-scope');
    }

    public function testRowExistsInScopeReturnsTrueForExistingActiveGlobalRow(): void
    {
        $id = $this->fixture->insertGlobal(1);

        self::assertTrue($this->manager->rowExistsInScope($this->pdo(), $this->globalConfig(), null, $id));
    }

    public function testRowExistsInScopeReturnsFalseForMissingGlobalRow(): void
    {
        self::assertFalse($this->manager->rowExistsInScope($this->pdo(), $this->globalConfig(), null, 999));
    }

    public function testRowExistsInScopeReturnsFalseForZeroGlobalId(): void
    {
        self::assertFalse($this->manager->rowExistsInScope($this->pdo(), $this->globalConfig(), null, 0));
    }

    public function testRowExistsInScopeReturnsFalseForNegativeGlobalId(): void
    {
        self::assertFalse($this->manager->rowExistsInScope($this->pdo(), $this->globalConfig(), null, -1));
    }

    public function testRowExistsInScopeReturnsFalseForSoftDeletedGlobalRowWithDefaultFiltering(): void
    {
        $id = $this->fixture->insertGlobal(1, deletedAt: '2026-01-01 00:00:00');

        self::assertFalse($this->manager->rowExistsInScope($this->pdo(), $this->globalConfig(), null, $id));
    }

    public function testRowExistsInScopeReturnsTrueForSoftDeletedGlobalRowWhenFilteringIsDisabled(): void
    {
        $id = $this->fixture->insertGlobal(1, deletedAt: '2026-01-01 00:00:00');

        self::assertTrue($this->manager->rowExistsInScope($this->pdo(), $this->globalConfigWithoutSoftDeletes(), null, $id));
    }

    public function testRowExistsInScopeReturnsTrueForExistingRowInsideRequestedScope(): void
    {
        $id = $this->fixture->insertScoped('scope-a', 1);

        self::assertTrue($this->manager->rowExistsInScope($this->pdo(), $this->scopedConfig(), 'scope-a', $id));
    }

    public function testRowExistsInScopeReturnsFalseForExistingRowQueriedUsingDifferentScope(): void
    {
        $id = $this->fixture->insertScoped('scope-a', 1);

        self::assertFalse($this->manager->rowExistsInScope($this->pdo(), $this->scopedConfig(), 'scope-b', $id));
    }

    public function testRowExistsInScopeDoesNotExposeScopeBRowInScopeA(): void
    {
        $id = $this->fixture->insertScoped('scope-b', 1);

        self::assertFalse($this->manager->rowExistsInScope($this->pdo(), $this->scopedConfig(), 'scope-a', $id));
    }

    public function testRowExistsInScopeAcceptsIntegerScopeValue(): void
    {
        $id = $this->fixture->insertScoped(123, 1);

        self::assertTrue($this->manager->rowExistsInScope($this->pdo(), $this->scopedConfig(), 123, $id));
    }

    public function testRowExistsInScopeAcceptsStringScopeValue(): void
    {
        $id = $this->fixture->insertScoped('string-scope', 1);

        self::assertTrue($this->manager->rowExistsInScope($this->pdo(), $this->scopedConfig(), 'string-scope', $id));
    }

    public function testRowExistsInScopeTreatsSoftDeletedScopedRowAsMissing(): void
    {
        $id = $this->fixture->insertScoped('scope-a', 1, deletedAt: '2026-01-01 00:00:00');

        self::assertFalse($this->manager->rowExistsInScope($this->pdo(), $this->scopedConfig(), 'scope-a', $id));
    }

    public function testRowExistsInScopeRejectsScopedConfigurationWithoutScopeValue(): void
    {
        $this->expectException(InvalidOrderingOperationException::class);

        $this->manager->rowExistsInScope($this->pdo(), $this->scopedConfig(), null, 1);
    }

    public function testRowExistsInScopeRejectsGlobalConfigurationWithScopeValue(): void
    {
        $this->expectException(InvalidOrderingOperationException::class);

        $this->manager->rowExistsInScope($this->pdo(), $this->globalConfig(), 'unexpected-scope', 1);
    }
}
