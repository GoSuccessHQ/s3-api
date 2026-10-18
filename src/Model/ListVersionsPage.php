<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * One page of ListObjectVersions, versions and delete markers in the order S3 sent them.
 */
final readonly class ListVersionsPage
{
    /**
     * @param list<ObjectVersion|DeleteMarker> $entries
     */
    public function __construct(
        public array $entries,
        public bool $isTruncated,
        public ?string $nextKeyMarker,
        public ?string $nextVersionIdMarker,
    ) {}
}
