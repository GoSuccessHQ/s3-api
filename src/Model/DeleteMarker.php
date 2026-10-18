<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

use DateTimeImmutable;

/**
 * A delete marker in a listing of versions: the key looks deleted while older
 * versions remain.
 */
final readonly class DeleteMarker
{
    public function __construct(
        public string $key,
        public string $versionId,
        public bool $isLatest,
        public DateTimeImmutable $lastModified,
    ) {}
}
