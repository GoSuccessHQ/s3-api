<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\CurlHttpClient;
use GoSuccess\S3\Http\HttpClient;
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
    public function bucket(string $name): Bucket
    {
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
