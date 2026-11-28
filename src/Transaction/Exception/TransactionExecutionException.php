<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;

/**
 * Reports a non-throwing PDO transaction-control failure, including the
 * required rollback for a raw Ordering move. Also reports an unconfirmed
 * owned Ordering rollback or a caller transaction that has ended at an
 * operation boundary that requires it to remain active.
 */
final class TransactionExecutionException extends SystemMaatifyException implements PhpPersistenceExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
