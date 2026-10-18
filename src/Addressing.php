<?php

declare(strict_types=1);

namespace GoSuccess\S3;

/**
 * Where a bucket and a key live: path style (`https://host/bucket/key`, which
 * every S3 server understands) or virtual-host style (`https://bucket.host/key`).
 * Keys are taken literally; for S3, `/a` and `a` are different objects.
 */
final readonly class Addressing
{
    public function __construct(
        private string $endpoint,
        private bool $pathStyle = true,
    ) {}

    /**
     * The host the request goes to, with a port unless it is the scheme's default.
     */
    public function host(string $bucket): string
    {
        $host = (string) parse_url($this->endpoint(), \PHP_URL_HOST);
        $port = parse_url($this->endpoint(), \PHP_URL_PORT);

        if (!$this->pathStyle) {
            $host = "{$bucket}.{$host}";
        }

        $usual = ($this->scheme() === 'https' && $port === 443) || ($this->scheme() === 'http' && $port === 80);

        return $port === null || $port === false || $usual ? $host : "{$host}:{$port}";
    }

    /**
     * `http` or `https`; an endpoint without a scheme is `https`.
     */
    public function scheme(): string
    {
        $scheme = strtolower((string) parse_url($this->endpoint, \PHP_URL_SCHEME));

        return $scheme === '' ? 'https' : $scheme;
    }

    /**
     * The unencoded path of the bucket or of a key in it.
     */
    public function path(string $bucket, string $key = ''): string
    {
        if ($this->pathStyle) {
            return $key === '' ? "/{$bucket}" : "/{$bucket}/{$key}";
        }

        return "/{$key}";
    }

    /**
     * The URL of the bucket or of a key, without a query.
     */
    public function url(string $bucket, string $key = ''): string
    {
        return "{$this->scheme()}://{$this->host($bucket)}" . Signature::encodePath($this->path($bucket, $key));
    }

    /**
     * The endpoint with a scheme, so parse_url() finds the host.
     */
    private function endpoint(): string
    {
        return str_contains($this->endpoint, '://') ? $this->endpoint : "https://{$this->endpoint}";
    }
}
