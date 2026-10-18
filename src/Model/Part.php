<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

use DateTimeImmutable;

/**
 * A part of a multipart upload.
 */
final readonly class Part
{
    /**
     * @param string $etag Without its quotes, as {@see \GoSuccess\S3\Bucket::uploadPart()} returns it.
     */
    public function __construct(
        public int $partNumber,
        public string $etag,
        public ?int $size = null,
        public ?DateTimeImmutable $lastModified = null,
    ) {}
}
