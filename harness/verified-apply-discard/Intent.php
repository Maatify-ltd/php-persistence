<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Proof;

/** Explicit direction for the proof callback independently of its payload. */
enum Intent
{
    case Apply;
    case Discard;
}
