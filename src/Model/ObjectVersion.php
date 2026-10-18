<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

use DateTimeImmutable;

/**
 * A version of an object in a listing of versions.
 */
final readonly class ObjectVersion
{
    public function __construct(
        public string $key,
        public string $versionId,
        public bool $isLatest,
        public int $size,
        public string $etag,
        public ?DateTimeImmutable $lastModified,
    ) {}
}
