<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * The result of deleting an object or a version.
 */
final readonly class DeleteResult
{
    /**
     * @param string|null $versionId    The deleted version, or the delete marker created.
     * @param bool        $deleteMarker Whether a delete marker was created or deleted.
     */
    public function __construct(
        public ?string $versionId,
        public bool $deleteMarker,
    ) {}
}
