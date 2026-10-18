<?php

declare(strict_types=1);

/**
 * Lists the objects of a bucket under a prefix.
 *
 * Usage: php examples/list.php <bucket> [<prefix>]
 */

use GoSuccess\S3\S3;

$s3 = require __DIR__ . '/bootstrap.php';
assert($s3 instanceof S3);

$bucket = $s3->bucket($argv[1] ?? 'backups');

foreach ($bucket->objects($argv[2] ?? '') as $object) {
    printf("%10d  %19s  %s\n", $object->size, $object->lastModified?->format('Y-m-d H:i:s') ?? '-', $object->key);
}
