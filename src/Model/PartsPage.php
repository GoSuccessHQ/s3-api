<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * The uploaded parts of a multipart upload (one page).
 */
final readonly class PartsPage
{
    /**
     * @param list<Part> $parts
     */
    public function __construct(
        public array $parts,
        public bool $isTruncated,
        public ?int $nextPartNumberMarker,
    ) {}
}
