<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Support;

use GoSuccess\S3\Addressing;
use GoSuccess\S3\ClientOptions;
use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\CurlHttpClient;
use GoSuccess\S3\Http\Method;
use GoSuccess\S3\Model\DeleteMarker;
use GoSuccess\S3\Model\ObjectIdentifier;
use GoSuccess\S3\S3;
use GoSuccess\S3\Signature;
use GoSuccess\S3\SystemClock;
use RuntimeException;

/**
 * A throwaway bucket on the RustFS of the integration tests. Creating and
 * deleting buckets is not part of the package, so this sends the signed raw
 * requests itself.
 */
final class RustFs
{
    public const string ACCESS_KEY = 's3-api-test';

    public const string SECRET_KEY = 's3-api-test-secret';

    public const string REGION = 'us-east-1';

    private readonly Connection $connection;

    public function __construct(public readonly string $endpoint)
    {
        $this->connection = new Connection(new Addressing($endpoint, true), new Signature(self::ACCESS_KEY, self::SECRET_KEY, self::REGION), new ClientOptions(), new CurlHttpClient(), new SystemClock());
    }

    /**
     * The endpoint from S3_TEST_ENDPOINT, or null to skip.
     */
    public static function fromEnvironment(): ?self
    {
        $endpoint = getenv('S3_TEST_ENDPOINT');

        return \is_string($endpoint) && $endpoint !== '' ? new self($endpoint) : null;
    }

    public function client(): S3
    {
        return new S3($this->endpoint, self::REGION, self::ACCESS_KEY, self::SECRET_KEY);
    }

    /**
     * Waits until the server answers, then creates a bucket with a random name.
     */
    public function createBucket(bool $objectLock = false, bool $versioning = false): string
    {
        $this->waitUntilReady();
        $name = 's3-api-test-' . bin2hex(random_bytes(6));
        $response = $this->connection->send(Method::Put, $name, '', [], '', $objectLock ? ['x-amz-bucket-object-lock-enabled' => 'true'] : []);

        if (!$response->isSuccessful) {
            throw new RuntimeException("Creating the test bucket failed with HTTP {$response->statusCode}: {$response->body}");
        }

        if ($versioning && !$objectLock) {
            $body = '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Status>Enabled</Status></VersioningConfiguration>';
            $this->connection->send(Method::Put, $name, '', ['versioning' => ''], $body, ['Content-Type' => 'application/xml', 'Content-MD5' => base64_encode(md5($body, true))]);
        }

        return $name;
    }

    /**
     * Removes every version and the bucket; bypasses governance retention, which
     * only this cleanup of a throwaway bucket may do.
     */
    public function dropBucket(string $name): void
    {
        $bucket = $this->client()->bucket($name);
        $batch = [];

        foreach ($bucket->versions() as $entry) {
            $batch[] = new ObjectIdentifier($entry->key, $entry instanceof DeleteMarker || $entry->versionId !== 'null' ? $entry->versionId : null);
        }

        foreach ($bucket->objects() as $object) {
            $batch[] = new ObjectIdentifier($object->key);
        }

        foreach (array_chunk($batch, 1000) as $chunk) {
            $bucket->deleteObjects($chunk, quiet: true, bypassGovernanceRetention: true);
        }

        $this->connection->send(Method::Delete, $name);
    }

    private function waitUntilReady(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $status = $this->connection->send(Method::Get, '')->statusCode;

            if ($status !== 503 && $status !== 0) {
                return;
            }

            usleep(500_000);
        }

        throw new RuntimeException('RustFS did not become ready.');
    }
}
