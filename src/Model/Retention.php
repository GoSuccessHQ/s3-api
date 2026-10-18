<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

use DateTimeImmutable;

/**
 * The retention of an object version: protected until the date.
 */
final readonly class Retention
{
    public function __construct(
        public RetentionMode $mode,
        public DateTimeImmutable $retainUntil,
    ) {}
}
