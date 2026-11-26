<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;

/**
 * Rejects invalid pagination defaults, bounds, whitelist or sort profiles,
 * including Keyset limit or signing-key configuration.
 */
final class InvalidPaginationConfigurationException extends SystemMaatifyException implements PhpPersistenceExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
