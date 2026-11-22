<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;

/**
 * Reports an ordering statement failure when PDO does not throw, an invalid
 * ordering result, a next position outside the PHP integer range, or exhaustion
 * of the locking tail-read budget.
 *
 * For tail-read exhaustion, getMeta() reports reason TAIL_READ_LIMIT_EXCEEDED
 * and the integer budget in maxTailReads.
 */
final class OrderingExecutionException extends SystemMaatifyException implements PhpPersistenceExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
