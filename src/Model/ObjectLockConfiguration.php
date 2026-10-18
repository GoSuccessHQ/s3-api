<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * The Object Lock configuration of a bucket and its default retention, if any.
 */
final readonly class ObjectLockConfiguration
{
    public function __construct(
        public bool $enabled,
        public ?RetentionMode $mode,
        public ?int $days,
        public ?int $years,
    ) {}
}
