<?php

declare(strict_types=1);

namespace GoSuccess\S3\Http;

/**
 * Immutable HTTP response returned by an {@see HttpClient}.
 */
final class Response
{
    /**
     * Whether the status code is in the 2xx range.
     */
    public bool $isSuccessful {
        get => $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * @param array<string, string> $headers      Map of lower-cased header name => value;
     *                                            repeated headers are joined with ", ".
     * @param string                $reasonPhrase Empty over HTTP/2.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly string $reasonPhrase = '',
    ) {}

    /**
     * Return a header value (case-insensitive), or an empty string if absent.
     */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
