<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Unit;

use GoSuccess\S3\Bucket;
use GoSuccess\S3\ClientOptions;
use GoSuccess\S3\Exception\ForbiddenException;
use GoSuccess\S3\Exception\SerializationException;
use GoSuccess\S3\Http\Response;
use GoSuccess\S3\Model\DeleteMarker;
use GoSuccess\S3\Model\ObjectVersion;
use GoSuccess\S3\Model\RetentionMode;
use GoSuccess\S3\Model\VersioningStatus;
use GoSuccess\S3\S3;
use GoSuccess\S3\Tests\Support\MockHttpClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bucket::class)]
#[CoversNamespace('GoSuccess\S3\Model')]
final class BucketReadTest extends TestCase
{
    private const string NS = 'xmlns="http://s3.amazonaws.com/doc/2006-03-01/"';

    public function testExists(): void
    {
        $http = new MockHttpClient(new Response(200), new Response(404));

        self::assertTrue($this->bucket($http)->exists());
        self::assertFalse($this->bucket($http)->exists());
        self::assertSame('HEAD', $http->requests[0]->method->value);
    }

    public function testAForbiddenBucketRaises(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->bucket(new MockHttpClient(new Response(403)))->exists();
    }

    public function testVersioning(): void
    {
        $http = new MockHttpClient(
            new Response(200, '<VersioningConfiguration ' . self::NS . '><Status>Enabled</Status></VersioningConfiguration>'),
            new Response(200, '<VersioningConfiguration ' . self::NS . '><Status>Suspended</Status></VersioningConfiguration>'),
            new Response(200, '<VersioningConfiguration ' . self::NS . '/>'),
        );
        $bucket = $this->bucket($http);

        self::assertSame(VersioningStatus::Enabled, $bucket->versioning()->status);
        self::assertSame(VersioningStatus::Suspended, $bucket->versioning()->status);
        self::assertNull($bucket->versioning()->status);
        self::assertSame('http://127.0.0.1:9100/backups?versioning=', $http->requests[0]->uri);
    }

    public function testObjectLock(): void
    {
        $http = new MockHttpClient(
            new Response(200, '<ObjectLockConfiguration ' . self::NS . '><ObjectLockEnabled>Enabled</ObjectLockEnabled><Rule><DefaultRetention><Mode>GOVERNANCE</Mode><Days>30</Days></DefaultRetention></Rule></ObjectLockConfiguration>'),
            new Response(200, '<ObjectLockConfiguration ' . self::NS . '><ObjectLockEnabled>Enabled</ObjectLockEnabled></ObjectLockConfiguration>'),
            new Response(404, '<Error><Code>ObjectLockConfigurationNotFoundError</Code><Message>Object Lock configuration does not exist for this bucket</Message></Error>'),
        );
        $bucket = $this->bucket($http);

        $withRule = $bucket->objectLock();
        self::assertNotNull($withRule);
        self::assertTrue($withRule->enabled);
        self::assertSame(RetentionMode::Governance, $withRule->mode);
        self::assertSame(30, $withRule->days);
        self::assertNull($withRule->years);

        $withoutRule = $bucket->objectLock();
        self::assertNotNull($withoutRule);
        self::assertTrue($withoutRule->enabled);
        self::assertNull($withoutRule->mode);

        self::assertNull($bucket->objectLock());
        self::assertSame('http://127.0.0.1:9100/backups?object-lock=', $http->requests[0]->uri);
    }

    public function testListObjectsPage(): void
    {
        $http = new MockHttpClient(new Response(200, '<ListBucketResult ' . self::NS . '><Name>backups</Name><Prefix>a/</Prefix><KeyCount>2</KeyCount><MaxKeys>2</MaxKeys><IsTruncated>true</IsTruncated><NextContinuationToken>tok2</NextContinuationToken>'
            . '<Contents><Key>a/1.txt</Key><LastModified>2026-10-18T10:00:00.000Z</LastModified><ETag>"e1"</ETag><Size>5</Size><StorageClass>STANDARD</StorageClass></Contents>'
            . '<Contents><Key>a/&amp;2.txt</Key><LastModified>2026-10-18T11:00:00.000Z</LastModified><ETag>"e2"</ETag><Size>7</Size></Contents>'
            . '<CommonPrefixes><Prefix>a/sub/</Prefix></CommonPrefixes></ListBucketResult>'));

        $page = $this->bucket($http)->listObjects('a/', maxKeys: 2, delimiter: '/');

        self::assertSame('http://127.0.0.1:9100/backups?delimiter=%2F&list-type=2&max-keys=2&prefix=a%2F', $http->requests[0]->uri);
        self::assertTrue($page->isTruncated);
        self::assertSame('tok2', $page->nextContinuationToken);
        self::assertSame(['a/1.txt', 'a/&2.txt'], array_map(static fn($object): string => $object->key, $page->objects));
        self::assertSame('e1', $page->objects[0]->etag);
        self::assertSame(5, $page->objects[0]->size);
        self::assertSame('2026-10-18T10:00:00+00:00', $page->objects[0]->lastModified?->format(\DATE_ATOM));
        self::assertSame('STANDARD', $page->objects[0]->storageClass);
        self::assertNull($page->objects[1]->storageClass);
        self::assertSame(['a/sub/'], $page->commonPrefixes);
    }

    public function testObjectsFollowsContinuationTokens(): void
    {
        $http = new MockHttpClient(
            new Response(200, '<ListBucketResult ' . self::NS . '><IsTruncated>true</IsTruncated><NextContinuationToken>t2</NextContinuationToken><Contents><Key>1</Key><LastModified>2026-10-18T10:00:00Z</LastModified><ETag>"a"</ETag><Size>1</Size></Contents></ListBucketResult>'),
            new Response(200, '<ListBucketResult ' . self::NS . '><IsTruncated>false</IsTruncated><Contents><Key>2</Key><LastModified>2026-10-18T10:00:00Z</LastModified><ETag>"b"</ETag><Size>2</Size></Contents></ListBucketResult>'),
        );

        $keys = array_map(static fn($object): string => $object->key, iterator_to_array($this->bucket($http)->objects(), false));

        self::assertSame(['1', '2'], $keys);
        self::assertStringContainsString('continuation-token=t2', $http->requests[1]->uri);
    }

    public function testVersionsFollowMarkersAndIncludeDeleteMarkers(): void
    {
        $http = new MockHttpClient(
            new Response(200, '<ListVersionsResult ' . self::NS . '><IsTruncated>true</IsTruncated><NextKeyMarker>k1</NextKeyMarker><NextVersionIdMarker>v1</NextVersionIdMarker>'
                . '<Version><Key>k1</Key><VersionId>v2</VersionId><IsLatest>true</IsLatest><LastModified>2026-10-18T10:00:00Z</LastModified><ETag>"x"</ETag><Size>3</Size></Version>'
                . '<DeleteMarker><Key>k0</Key><VersionId>d1</VersionId><IsLatest>true</IsLatest><LastModified>2026-10-18T09:00:00Z</LastModified></DeleteMarker></ListVersionsResult>'),
            new Response(200, '<ListVersionsResult ' . self::NS . '><IsTruncated>false</IsTruncated><Version><Key>k1</Key><VersionId>v1</VersionId><IsLatest>false</IsLatest><LastModified>2026-10-17T10:00:00Z</LastModified><ETag>"y"</ETag><Size>4</Size></Version></ListVersionsResult>'),
        );

        $entries = iterator_to_array($this->bucket($http)->versions('k'), false);

        self::assertCount(3, $entries);
        self::assertInstanceOf(ObjectVersion::class, $entries[0]);
        self::assertTrue($entries[0]->isLatest);
        self::assertSame(3, $entries[0]->size);
        self::assertInstanceOf(DeleteMarker::class, $entries[1]);
        self::assertSame('d1', $entries[1]->versionId);
        self::assertInstanceOf(ObjectVersion::class, $entries[2]);
        self::assertStringContainsString('key-marker=k1', $http->requests[1]->uri);
        self::assertStringContainsString('version-id-marker=v1', $http->requests[1]->uri);
        self::assertStringContainsString('versions=', $http->requests[0]->uri);
    }

    public function testTruncatedWithoutMarkerEnds(): void
    {
        $http = new MockHttpClient(
            new Response(200, '<ListVersionsResult ' . self::NS . '><IsTruncated>true</IsTruncated><Version><Key>k</Key><VersionId>v</VersionId><IsLatest>true</IsLatest><LastModified>2026-10-18T10:00:00Z</LastModified><ETag>"x"</ETag><Size>1</Size></Version></ListVersionsResult>'),
            new Response(200, '<ListBucketResult ' . self::NS . '><IsTruncated>true</IsTruncated></ListBucketResult>'),
        );
        $bucket = $this->bucket($http);

        self::assertCount(1, iterator_to_array($bucket->versions(), false));
        self::assertCount(0, iterator_to_array($bucket->objects(), false));
        self::assertSame(2, $http->callCount());
    }

    public function testUnreadableLastModifiedIsNull(): void
    {
        $http = new MockHttpClient(new Response(200, '<ListBucketResult ' . self::NS . '><IsTruncated>false</IsTruncated><Contents><Key>k</Key><LastModified>2026-02-30T10:00:00Z</LastModified><ETag>"e"</ETag><Size>1</Size></Contents><Contents><Key>j</Key><ETag>"e"</ETag><Size>1</Size></Contents></ListBucketResult>'));

        $page = $this->bucket($http)->listObjects();

        self::assertNull($page->objects[0]->lastModified);
        self::assertNull($page->objects[1]->lastModified);
    }

    public function testVersionsNeedAKeyMarkerToContinue(): void
    {
        $http = new MockHttpClient(new Response(200, '<ListVersionsResult ' . self::NS . '><IsTruncated>true</IsTruncated><NextVersionIdMarker>v1</NextVersionIdMarker></ListVersionsResult>'));

        self::assertSame([], iterator_to_array($this->bucket($http)->versions(), false));
        self::assertSame(1, $http->callCount());
    }

    public function testARepeatedTokenStopsWithAnError(): void
    {
        $page = '<ListBucketResult ' . self::NS . '><IsTruncated>true</IsTruncated><NextContinuationToken>same</NextContinuationToken></ListBucketResult>';
        $http = new MockHttpClient(new Response(200, $page), new Response(200, $page));

        $this->expectException(SerializationException::class);

        iterator_to_array($this->bucket($http)->objects(), false);
    }

    public function testInvalidBucketNamesAndEndpointPathsAreRefused(): void
    {
        $s3 = new S3('http://127.0.0.1:9100', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'secret');

        foreach (['evil.example/', 'a#b', 'a b', '', 'a?b'] as $name) {
            try {
                $s3->bucket($name);
                self::fail("Accepted {$name}.");
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(InvalidArgumentException::class);

        new S3('https://s3.example.test/prefix', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'secret');
    }

    public function testEmptyListing(): void
    {
        $page = $this->bucket(new MockHttpClient(new Response(200, '<ListBucketResult ' . self::NS . '><Name>b</Name><IsTruncated>false</IsTruncated></ListBucketResult>')))->listObjects();

        self::assertSame([], $page->objects);
        self::assertFalse($page->isTruncated);
        self::assertNull($page->nextContinuationToken);
    }

    private function bucket(MockHttpClient $http): Bucket
    {
        return new S3('http://127.0.0.1:9100', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'secret', options: new ClientOptions(retryBaseDelay: 0.0), httpClient: $http)->bucket('backups');
    }
}
