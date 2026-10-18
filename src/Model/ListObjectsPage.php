<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * One page of ListObjectsV2.
 */
final readonly class ListObjectsPage
{
    /**
     * @param list<ObjectSummary> $objects
     * @param list<string>        $commonPrefixes With a delimiter: the "folders".
     */
    public function __construct(
        public array $objects,
        public array $commonPrefixes,
        public bool $isTruncated,
        public ?string $nextContinuationToken,
    ) {}
}
