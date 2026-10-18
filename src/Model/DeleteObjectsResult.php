<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * The result of a multi-object delete: what went, and what did not and why.
 * With `quiet`, S3 reports only the errors.
 */
final readonly class DeleteObjectsResult
{
    /**
     * @param list<ObjectIdentifier> $deleted
     * @param list<DeleteError>      $errors
     */
    public function __construct(
        public array $deleted,
        public array $errors,
    ) {}
}
