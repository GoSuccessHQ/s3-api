<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * A key, or one version of it, in a multi-object delete.
 */
final readonly class ObjectIdentifier
{
    public function __construct(
        public string $key,
        public ?string $versionId = null,
    ) {}
}
