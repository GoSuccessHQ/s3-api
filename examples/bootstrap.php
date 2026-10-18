<?php

declare(strict_types=1);

/**
 * Shared setup of the examples: a client from S3_ENDPOINT, S3_REGION,
 * S3_ACCESS_KEY and S3_SECRET_KEY (e.g. `set -a; . ./.env; set +a`), or the
 * local RustFS of `composer rustfs:start`.
 */

use GoSuccess\S3\S3;

require __DIR__ . '/../vendor/autoload.php';

$env = static fn(string $name, string $default): string => is_string(getenv($name)) && getenv($name) !== '' ? (string) getenv($name) : $default;

return new S3(
    $env('S3_ENDPOINT', 'http://127.0.0.1:9100'),
    $env('S3_REGION', 'us-east-1'),
    $env('S3_ACCESS_KEY', 's3-api-test'),
    $env('S3_SECRET_KEY', 's3-api-test-secret'),
);
