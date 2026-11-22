<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;

/**
 * Package-defined rejection or unconfirmed operation in a Protected or
 * Verified transaction checkout. An escaping driver or callback throwable
 * retains its own type and identity.
 */
final class ProtectedTransactionException extends SystemMaatifyException implements PhpPersistenceExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
