<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\CurlHttpClient;
use GoSuccess\S3\Http\HttpClient;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Client for an S3-compatible endpoint (AWS, Impossible Cloud, RustFS, MinIO, …).
 */
final class S3
{
    private readonly Connection $connection;

    /**
     * @param string $endpoint  e.g. `https://eu-central-2.storage.impossibleapi.net`
     * @param bool   $pathStyle Path style (`/bucket/key`) works everywhere; virtual-host style
     *                          (`bucket.host/key`) where the provider requires it.
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $accessKey,
        #[SensitiveParameter]
        string $secretKey,
        bool $pathStyle = true,
        ClientOptions $options = new ClientOptions(),
        ?HttpClient $httpClient = null,
        Clock $clock = new SystemClock(),
    ) {
        $path = (string) parse_url(str_contains($endpoint, '://') ? $endpoint : "https://{$endpoint}", \PHP_URL_PATH);

        if (trim($path, '/') !== '') {
            throw new InvalidArgumentException('The endpoint must not have a path; S3 addresses buckets at the root.');
        }

        $this->connection = new Connection(
            new Addressing($endpoint, $pathStyle),
            new Signature($accessKey, $secretKey, $region),
            $options,
            $httpClient ?? new CurlHttpClient($options->timeout, $options->connectTimeout),
            $clock,
        );
    }

    /**
     * The operations on a bucket.
     */
    /**
     * @throws InvalidArgumentException for a name with characters no S3 server
     *                                   accepts, which could also change the host
     *                                   in virtual-host style
     */
    public function bucket(string $name): Bucket
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D', $name) !== 1) {
            throw new InvalidArgumentException('A bucket name consists of letters, digits, ".", "_" and "-".');
        }

        return new Bucket($name, $this->connection);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'region' => $this->region, 'accessKey' => $this->accessKey, 'secretKey' => '***'];
    }
}
