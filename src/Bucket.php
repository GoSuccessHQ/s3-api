<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Exception\TransportException;
use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\Method;

/**
 * The operations on one bucket.
 */
final readonly class Bucket
{
    /**
     * @internal Use {@see S3::bucket()}.
     */
    public function __construct(
        public string $name,
        private Connection $connection,
    ) {}

    /**
     * Whether the bucket exists and the key may see it (`HEAD`); a missing bucket is false.
     *
     * @throws ApiException       for another error, e.g. 403 when the key may not see it
     * @throws TransportException when the request keeps failing
     */
    public function exists(): bool
    {
        $response = $this->connection->send(Method::Head, $this->name);

        return match (true) {
            $response->isSuccessful => true,
            $response->statusCode === 404 => false,
            default => throw $this->connection->failure($response),
        };
    }
}
