<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * A key a multi-object delete could not delete, with S3's reason.
 */
final readonly class DeleteError
{
    public function __construct(
        public string $key,
        public ?string $versionId,
        public string $code,
        public ?string $message,
    ) {}
}
