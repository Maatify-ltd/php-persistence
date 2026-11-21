<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use RuntimeException;
use SensitiveParameter;

/** Loaded only in the isolated cursor issuance test. */
function hash_hmac(string $algo, string $data, #[SensitiveParameter] string $key, bool $binary = false): string
{
    if ($binary && str_starts_with($data, 'v1.old.')) {
        throw new RuntimeException('Synthetic cursor signing failure.');
    }

    return \hash_hmac($algo, $data, $key, $binary);
}
