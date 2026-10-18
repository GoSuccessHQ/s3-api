<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * The result of writing an object.
 */
final readonly class PutResult
{
    /**
     * @param string      $etag      Without its quotes.
     * @param string|null $versionId Only in a versioned bucket.
     */
    public function __construct(
        public string $etag,
        public ?string $versionId,
    ) {}
}
