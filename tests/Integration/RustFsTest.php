<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Integration;

use DateTimeImmutable;
use GoSuccess\S3\Bucket;
use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Model\DeleteMarker;
use GoSuccess\S3\Model\ObjectIdentifier;
use GoSuccess\S3\Model\ObjectVersion;
use GoSuccess\S3\Model\Part;
use GoSuccess\S3\Model\Retention;
use GoSuccess\S3\Model\RetentionMode;
use GoSuccess\S3\Model\VersioningStatus;
use GoSuccess\S3\Tests\Support\RustFs;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Against a real S3 server: RustFS 1.0.1 (`composer rustfs:start`); skipped
 * unless S3_TEST_ENDPOINT is set. Every test uses its own bucket and removes it.
 */
#[CoversNothing]
final class RustFsTest extends TestCase
{
    private RustFs $rustFs;

    /** @var list<string> */
    private array $buckets = [];

    protected function setUp(): void
    {
        $rustFs = RustFs::fromEnvironment();

        if ($rustFs === null) {
            self::markTestSkipped('Set S3_TEST_ENDPOINT (composer rustfs:start: http://127.0.0.1:9100).');
        }

        $this->rustFs = $rustFs;
    }

    protected function tearDown(): void
    {
        foreach ($this->buckets as $name) {
            $this->rustFs->dropBucket($name);
        }
    }

    public function testExistence(): void
    {
        $bucket = $this->bucket();

        self::assertTrue($bucket->exists());
        self::assertFalse($this->rustFs->client()->bucket('s3-api-test-missing-' . bin2hex(random_bytes(4)))->exists());
    }

    public function testObjectsWithDifficultKeys(): void
    {
        $bucket = $this->bucket();

        foreach (['a b+c%/ü/x.txt', '//double', 'plain.txt', 'q?x=1&y=2'] as $key) {
            $put = $bucket->putObject($key, "content of {$key}", 'text/plain', ['origin' => 'test']);
            $object = $bucket->getObject($key);

            self::assertSame("content of {$key}", $object->body, $key);
            self::assertSame($put->etag, $object->head->etag, $key);
            self::assertSame('test', $object->head->metadata['origin'] ?? null, $key);
            self::assertSame(\strlen("content of {$key}"), $bucket->headObject($key)?->size, $key);
        }

        $bucket->deleteObject('plain.txt');

        self::assertNull($bucket->headObject('plain.txt'));
        $keys = array_map(static fn($object): string => $object->key, iterator_to_array($bucket->objects(), false));
        self::assertContains('a b+c%/ü/x.txt', $keys);
        self::assertContains('q?x=1&y=2', $keys);
        // AWS keeps "//double"; RustFS 1.0.1 strips leading slashes (put and get alike), so the key lists as "double".
        self::assertNotEmpty(array_intersect(['//double', 'double'], $keys));
        self::assertCount(3, $keys);
    }

    public function testListingPagesAndPrefixes(): void
    {
        $bucket = $this->bucket();

        foreach (['p/1', 'p/2', 'p/3', 'p/sub/4', 'other'] as $key) {
            $bucket->putObject($key, 'x');
        }

        $page = $bucket->listObjects('p/', maxKeys: 2);
        self::assertCount(2, $page->objects);
        self::assertTrue($page->isTruncated);
        self::assertNotNull($page->nextContinuationToken);

        self::assertSame(['p/1', 'p/2', 'p/3', 'p/sub/4'], array_map(static fn($object): string => $object->key, iterator_to_array($bucket->objects('p/'), false)));
        self::assertSame(['p/sub/'], $bucket->listObjects('p/', delimiter: '/')->commonPrefixes);
    }

    public function testVersionsAndDeleteMarkers(): void
    {
        $bucket = $this->bucket(versioning: true);

        if ($bucket->versioning()->status !== VersioningStatus::Enabled) {
            self::markTestSkipped('RustFS did not enable versioning.');
        }

        $first = $bucket->putObject('k', 'one');
        $second = $bucket->putObject('k', 'two');
        $deleted = $bucket->deleteObject('k');

        self::assertNotNull($first->versionId);
        self::assertNotSame($first->versionId, $second->versionId);
        self::assertTrue($deleted->deleteMarker);
        self::assertNull($bucket->headObject('k'));
        self::assertSame('one', $bucket->getObject('k', $first->versionId)->body);

        $entries = iterator_to_array($bucket->versions('k'), false);
        self::assertCount(2, array_filter($entries, static fn($entry): bool => $entry instanceof ObjectVersion));
        self::assertCount(1, array_filter($entries, static fn($entry): bool => $entry instanceof DeleteMarker));
    }

    public function testMultiObjectDelete(): void
    {
        $bucket = $this->bucket();

        foreach (['a&b', '<c>', 'd'] as $key) {
            $bucket->putObject($key, 'x');
        }

        $result = $bucket->deleteObjects([new ObjectIdentifier('a&b'), new ObjectIdentifier('<c>')]);

        self::assertSame([], $result->errors);
        self::assertEqualsCanonicalizing(['a&b', '<c>'], array_map(static fn($object): string => $object->key, $result->deleted));
        self::assertSame(['d'], array_map(static fn($object): string => $object->key, iterator_to_array($bucket->objects(), false)));
    }

    public function testMultipartUpload(): void
    {
        $bucket = $this->bucket();
        $first = str_repeat('a', 5 * 1024 * 1024);

        $uploadId = $bucket->createMultipartUpload('big', 'application/octet-stream');
        $parts = [new Part(1, $bucket->uploadPart('big', $uploadId, 1, $first)), new Part(2, $bucket->uploadPart('big', $uploadId, 2, 'b'))];
        self::assertCount(2, $bucket->listParts('big', $uploadId)->parts);
        $bucket->completeMultipartUpload('big', $uploadId, $parts);

        self::assertSame(5 * 1024 * 1024 + 1, $bucket->headObject('big')?->size);

        $aborted = $bucket->createMultipartUpload('gone');
        $bucket->uploadPart('gone', $aborted, 1, 'x');
        $bucket->abortMultipartUpload('gone', $aborted);
        self::assertNull($bucket->headObject('gone'));
    }

    public function testPresignedUrlDownloadsTheObject(): void
    {
        $bucket = $this->bucket();
        $bucket->putObject('dir/Backup "Haupt" ä.sql', 'SELECT 1;');

        $url = $bucket->presignedGetUrl('dir/Backup "Haupt" ä.sql', 60, downloadName: 'Backup "Haupt" ä.sql');
        $headers = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$headers): int {
            $headers[] = trim($line);

            return \strlen($line);
        }]);
        $body = curl_exec($handle);

        self::assertSame('SELECT 1;', $body);
        self::assertSame(200, curl_getinfo($handle, \CURLINFO_RESPONSE_CODE));
        self::assertNotEmpty(array_filter($headers, static fn(string $line): bool => str_starts_with(strtolower($line), 'content-disposition: attachment')));
    }

    public function testDownloadToAFile(): void
    {
        $bucket = $this->bucket();
        $bucket->putObject('file', str_repeat('z', 100_000));
        $path = (string) tempnam(sys_get_temp_dir(), 's3-api-test-');

        try {
            self::assertSame(100_000, $bucket->getObjectToFile('file', $path)->size);
            self::assertSame(100_000, filesize($path));
        } finally {
            @unlink($path);
        }
    }

    public function testObjectLockAndRetention(): void
    {
        $bucket = $this->bucket(objectLock: true);
        $lock = $bucket->objectLock();

        if ($lock === null || !$lock->enabled) {
            self::markTestSkipped('RustFS did not enable Object Lock on the bucket.');
        }

        $put = $bucket->putObject('locked', 'keep', retention: new Retention(RetentionMode::Governance, new DateTimeImmutable('+1 hour')));
        self::assertNotNull($put->versionId);
        self::assertSame(RetentionMode::Governance, $bucket->getRetention('locked', $put->versionId)?->mode);

        try {
            $bucket->deleteObject('locked', $put->versionId);
            self::fail('A retained version was deleted without bypass.');
        } catch (ApiException $exception) {
            self::assertContains($exception->statusCode, [400, 403]);
        }

        $free = $bucket->putObject('free', 'no retention');
        self::assertNull($bucket->getRetention('free', $free->versionId));
    }

    private function bucket(bool $objectLock = false, bool $versioning = false): Bucket
    {
        $name = $this->rustFs->createBucket($objectLock, $versioning);
        $this->buckets[] = $name;

        return $this->rustFs->client()->bucket($name);
    }
}
