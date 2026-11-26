<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;

/**
 * Reports refusal of a C1 checkout, an unavailable operation, unconfirmed
 * session, resource or transaction control, or invalid discard evidence.
 * A thrown driver or mapper error retains its identity.
 */
final class SnapshotPaginationException extends SystemMaatifyException implements PhpPersistenceExceptionInterface
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
