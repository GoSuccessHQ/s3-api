<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

use DateTimeImmutable;

/**
 * An object in a listing.
 */
final readonly class ObjectSummary
{
    /**
     * @param string                 $etag         Without the quotes S3 puts around it.
     * @param DateTimeImmutable|null $lastModified null when the server sent none or an unreadable one.
     */
    public function __construct(
        public string $key,
        public int $size,
        public string $etag,
        public ?DateTimeImmutable $lastModified,
        public ?string $storageClass,
    ) {}
}
