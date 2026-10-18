<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use DateTimeImmutable;

/**
 * The time a request is signed with; replaceable in tests.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
