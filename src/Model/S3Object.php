<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

/**
 * An object's content and headers.
 */
final readonly class S3Object
{
    public function __construct(
        public string $body,
        public ObjectHead $head,
    ) {}
}
