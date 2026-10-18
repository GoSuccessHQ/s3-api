<?php

declare(strict_types=1);

namespace GoSuccess\S3\Model;

use DateTimeImmutable;
use DateTimeZone;
use GoSuccess\S3\Http\Response;

/**
 * What the headers of an object answer tell about it.
 */
final readonly class ObjectHead
{
    /**
     * @param string                $etag     Without its quotes.
     * @param array<string, string> $metadata The `x-amz-meta-*` headers without the prefix.
     */
    public function __construct(
        public int $size,
        public string $etag,
        public ?DateTimeImmutable $lastModified,
        public ?string $versionId,
        public ?string $contentType,
        public array $metadata,
    ) {}

    /**
     * @internal
     */
    public static function fromResponse(Response $response): self
    {
        $metadata = [];

        foreach ($response->headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-')) {
                $metadata[substr($name, 11)] = $value;
            }
        }

        $lastModified = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $response->header('last-modified'), new DateTimeZone('UTC'));

        return new self(
            size: (int) $response->header('content-length'),
            etag: trim($response->header('etag'), '" '),
            lastModified: $lastModified === false ? null : $lastModified,
            versionId: self::optional($response->header('x-amz-version-id')),
            contentType: self::optional($response->header('content-type')),
            metadata: $metadata,
        );
    }

    private static function optional(string $value): ?string
    {
        return $value === '' || $value === 'null' ? null : $value;
    }
}
