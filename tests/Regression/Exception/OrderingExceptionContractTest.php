<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression\Exception;

use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Exceptions\Exception\Validation\ValidationMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingConfigurationException;
use Maatify\PhpPersistence\Ordering\Exception\InvalidOrderingOperationException;
use Maatify\PhpPersistence\Ordering\Exception\OrderingExecutionException;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OrderingExceptionContractTest extends TestCase
{
    public function testInvalidOrderingConfigurationExceptionContract(): void
    {
        $class = new ReflectionClass(InvalidOrderingConfigurationException::class);
        $exception = new InvalidOrderingConfigurationException('invalid ordering configuration');

        self::assertTrue($class->isFinal());
        self::assertInstanceOf(SystemMaatifyException::class, $exception);
        self::assertInstanceOf(PhpPersistenceExceptionInterface::class, $exception);
        self::assertSame(ErrorCodeEnum::MAATIFY_ERROR, $exception->getErrorCode());
    }

    public function testInvalidOrderingOperationExceptionContract(): void
    {
        $class = new ReflectionClass(InvalidOrderingOperationException::class);
        $exception = new InvalidOrderingOperationException('invalid ordering operation');

        self::assertTrue($class->isFinal());
        self::assertInstanceOf(ValidationMaatifyException::class, $exception);
        self::assertInstanceOf(PhpPersistenceExceptionInterface::class, $exception);
        self::assertSame(ErrorCodeEnum::INVALID_ARGUMENT, $exception->getErrorCode());
    }

    public function testOrderingExecutionExceptionContract(): void
    {
        $class = new ReflectionClass(OrderingExecutionException::class);
        $exception = new OrderingExecutionException('ordering statement failure');

        self::assertTrue($class->isFinal());
        self::assertInstanceOf(SystemMaatifyException::class, $exception);
        self::assertInstanceOf(PhpPersistenceExceptionInterface::class, $exception);
        self::assertSame(ErrorCodeEnum::MAATIFY_ERROR, $exception->getErrorCode());
    }

    /**
     * No ordering-specific exception marker is carried (legacy never declared one beyond the shared
     * PersistenceException), and the legacy OrderingTransactionException and its @deprecated Gen1 runner
     * dependency are not carried into the new package (DEC-007 items 2 and 4).
     */
    public function testNoLegacyOrderingTransactionExceptionOrDeprecatedMarkerIsCarried(): void
    {
        self::assertFalse(class_exists('Maatify\\PhpPersistence\\Ordering\\Exception\\OrderingTransactionException'));
        self::assertFalse(interface_exists('Maatify\\PhpPersistence\\Ordering\\Exception\\OrderingException'));
    }

    /**
     * Ordering depends on Transaction only (Issue #16 §1): the manager carries no own savepoint
     * helper and reuses the Transaction-owned SavepointScope (DEC-011 item 2), not a redesigned
     * duplicate under Ordering.
     */
    public function testOrderingHasNoOwnSavepointHelperAndDoesNotReviveTheInternalOperationSavepoint(): void
    {
        self::assertFalse(class_exists('Maatify\\PhpPersistence\\Ordering\\Internal\\OperationSavepoint'));
        self::assertFalse(class_exists('Maatify\\PhpPersistence\\Ordering\\Repository\\OperationSavepoint'));

        $managerFile = (new ReflectionClass(ScopedOrderingManager::class))->getFileName();
        self::assertIsString($managerFile);
        $managerSource = file_get_contents($managerFile);
        self::assertIsString($managerSource);
        self::assertStringContainsString('Maatify\\PhpPersistence\\Transaction\\Repository\\SavepointScope', $managerSource);
        self::assertStringContainsString('Maatify\\PhpPersistence\\Transaction\\Repository\\PdoTransactionRunner', $managerSource);
    }

    public function testInvalidOrderingConfigurationExceptionRepresentativeTrigger(): void
    {
        $this->expectException(InvalidOrderingConfigurationException::class);

        new ScopedOrderingConfig('items', nullableScope: true);
    }

    public function testInvalidOrderingOperationExceptionRepresentativeTrigger(): void
    {
        $this->expectException(InvalidOrderingOperationException::class);

        (new ScopedOrderingManager())->rowExistsInScope(
            new class extends \PDO {
                public function __construct() {}
            },
            new ScopedOrderingConfig('items', scopeColumn: 'tenant_id'),
            null,
            1,
        );
    }
}
