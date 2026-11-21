<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\Validation\ValidationMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;

/** A supplied Keyset cursor is not valid for this read. */
final class InvalidKeysetCursorException extends ValidationMaatifyException implements PhpPersistenceExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::INVALID_ARGUMENT;
    }
}
