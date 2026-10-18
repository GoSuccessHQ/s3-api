<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * The versioning configuration of a bucket.
 */
final readonly class Versioning
{
    /**
     * @param VersioningStatus|null $status null when versioning was never enabled
     */
    public function __construct(
        public ?VersioningStatus $status,
    ) {}

    public function isEnabled(): bool
    {
        return $this->status === VersioningStatus::Enabled;
    }
}
