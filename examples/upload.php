<?php

declare(strict_types=1);

/**
 * Uploads a small file and prints a download link valid for an hour.
 *
 * Usage: php examples/upload.php <bucket> <file>
 */

use GoSuccess\S3\S3;

$s3 = require __DIR__ . '/bootstrap.php';
assert($s3 instanceof S3);

$file = $argv[2] ?? __FILE__;
$content = file_get_contents($file);

if ($content === false) {
    fwrite(STDERR, "Cannot read {$file}.\n");

    exit(1);
}

$bucket = $s3->bucket($argv[1] ?? 'backups');
$key = 'examples/' . basename($file);
$result = $bucket->putObject($key, $content, 'application/octet-stream');

printf("ETag %s, version %s\n", $result->etag, $result->versionId ?? '-');
echo $bucket->presignedGetUrl($key, 3600, downloadName: basename($file)), "\n";
