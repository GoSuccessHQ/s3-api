<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use DateTimeImmutable;

/**
 * The system time.
 */
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
