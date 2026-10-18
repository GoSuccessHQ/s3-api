<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Unit;

use DateTimeImmutable;
use GoSuccess\S3\Bucket;
use GoSuccess\S3\ClientOptions;
use GoSuccess\S3\Clock;
use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Exception\NotFoundException;
use GoSuccess\S3\Http\Response;
use GoSuccess\S3\Model\ObjectIdentifier;
use GoSuccess\S3\Model\Part;
use GoSuccess\S3\Model\Retention;
use GoSuccess\S3\Model\RetentionMode;
use GoSuccess\S3\S3;
use GoSuccess\S3\Tests\Support\MockHttpClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bucket::class)]
#[CoversNamespace('GoSuccess\S3\Model')]
final class BucketObjectTest extends TestCase
{
    public function testPutSendsContentMd5AndMetadata(): void
    {
        $http = new MockHttpClient(new Response(200, '', ['etag' => '"abc"', 'x-amz-version-id' => 'v1']));

        $result = $this->bucket($http)->putObject('dir/file.txt', 'hello', 'text/plain', ['origin' => 'probe']);

        $request = $http->requests[0];
        self::assertSame('PUT', $request->method->value);
        self::assertSame('http://127.0.0.1:9100/backups/dir/file.txt', $request->uri);
        self::assertSame('hello', $request->body);
        self::assertSame(base64_encode(md5('hello', true)), $request->headers['Content-MD5']);
        self::assertSame('text/plain', $request->headers['Content-Type']);
        self::assertSame('probe', $request->headers['x-amz-meta-origin']);
        self::assertArrayNotHasKey('x-amz-object-lock-mode', $request->headers);
        self::assertSame('abc', $result->etag);
        self::assertSame('v1', $result->versionId);
    }

    public function testPutWithRetentionSendsLockHeaders(): void
    {
        $http = new MockHttpClient(new Response(200, '', ['etag' => '"abc"']));

        $result = $this->bucket($http)->putObject('k', 'x', retention: new Retention(RetentionMode::Governance, new DateTimeImmutable('2026-11-01T12:00:00+01:00')));

        self::assertSame('GOVERNANCE', $http->requests[0]->headers['x-amz-object-lock-mode']);
        self::assertSame('2026-11-01T11:00:00Z', $http->requests[0]->headers['x-amz-object-lock-retain-until-date']);
        self::assertNull($result->versionId);
    }

    public function testGetObjectReturnsBodyAndHead(): void
    {
        $http = new MockHttpClient(new Response(200, 'content', ['content-length' => '7', 'etag' => '"e"', 'last-modified' => 'Sun, 18 Oct 2026 10:00:00 GMT', 'x-amz-version-id' => 'v2', 'content-type' => 'text/plain', 'x-amz-meta-origin' => 'probe']));

        $object = $this->bucket($http)->getObject('k', 'v2');

        self::assertSame('http://127.0.0.1:9100/backups/k?versionId=v2', $http->requests[0]->uri);
        self::assertSame('content', $object->body);
        self::assertSame(7, $object->head->size);
        self::assertSame('e', $object->head->etag);
        self::assertSame('2026-10-18T10:00:00+00:00', $object->head->lastModified?->format(\DATE_ATOM));
        self::assertSame('v2', $object->head->versionId);
        self::assertSame('text/plain', $object->head->contentType);
        self::assertSame(['origin' => 'probe'], $object->head->metadata);
    }

    public function testGetObjectOfAMissingKeyRaises(): void
    {
        $this->expectException(NotFoundException::class);

        $this->bucket(new MockHttpClient(new Response(404, '<Error><Code>NoSuchKey</Code></Error>')))->getObject('k');
    }

    public function testGetObjectToFileWritesTheFile(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 's3-api-test-');

        try {
            $head = $this->bucket(new MockHttpClient(new Response(200, 'file content', ['content-length' => '12', 'etag' => '"e"'])))->getObjectToFile('k', $path);

            self::assertSame('file content', file_get_contents($path));
            self::assertSame(12, $head->size);
        } finally {
            @unlink($path);
        }
    }

    public function testAFailedDownloadRemovesTheFileAndRaises(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 's3-api-test-');

        try {
            $this->bucket(new MockHttpClient(new Response(404, '<Error><Code>NoSuchKey</Code><Message>missing</Message></Error>')))->getObjectToFile('k', $path);
            self::fail('No exception.');
        } catch (NotFoundException $exception) {
            self::assertSame('NoSuchKey', $exception->errorCode);
            self::assertFileDoesNotExist($path);
        }
    }

    public function testHeadOfAMissingObjectIsNull(): void
    {
        $http = new MockHttpClient(new Response(404), new Response(200, '', ['content-length' => '3', 'etag' => '"e"']));
        $bucket = $this->bucket($http);

        self::assertNull($bucket->headObject('missing'));
        self::assertSame(3, $bucket->headObject('there')?->size);
        self::assertSame('HEAD', $http->requests[0]->method->value);
    }

    public function testDeleteNeverBypassesByDefault(): void
    {
        $http = new MockHttpClient(new Response(204, '', ['x-amz-delete-marker' => 'true', 'x-amz-version-id' => 'dm1']));

        $result = $this->bucket($http)->deleteObject('k');

        self::assertSame('DELETE', $http->requests[0]->method->value);
        self::assertArrayNotHasKey('x-amz-bypass-governance-retention', $http->requests[0]->headers);
        self::assertTrue($result->deleteMarker);
        self::assertSame('dm1', $result->versionId);
    }

    public function testDeleteBypassesOnlyWhenAsked(): void
    {
        $http = new MockHttpClient(new Response(204));

        $this->bucket($http)->deleteObject('k', 'v1', bypassGovernanceRetention: true);

        self::assertSame('http://127.0.0.1:9100/backups/k?versionId=v1', $http->requests[0]->uri);
        self::assertSame('true', $http->requests[0]->headers['x-amz-bypass-governance-retention']);
    }

    public function testARefusedDeleteRaises(): void
    {
        $this->expectException(ApiException::class);

        $this->bucket(new MockHttpClient(new Response(403, '<Error><Code>AccessDenied</Code></Error>')))->deleteObject('k', 'v1');
    }

    public function testDeleteObjectsEscapesKeysAndSendsMd5(): void
    {
        $http = new MockHttpClient(new Response(200, '<DeleteResult><Deleted><Key>a&amp;b</Key><VersionId>v1</VersionId></Deleted></DeleteResult>'));

        $result = $this->bucket($http)->deleteObjects([new ObjectIdentifier('a&b', 'v1'), new ObjectIdentifier('<c>"')], quiet: true);

        $request = $http->requests[0];
        self::assertSame('POST', $request->method->value);
        self::assertSame('http://127.0.0.1:9100/backups?delete=', $request->uri);
        self::assertSame('<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet><Object><Key>a&amp;b</Key><VersionId>v1</VersionId></Object><Object><Key>&lt;c&gt;&quot;</Key></Object></Delete>', $request->body);
        self::assertSame(base64_encode(md5((string) $request->body, true)), $request->headers['Content-MD5']);
        self::assertSame('application/xml', $request->headers['Content-Type']);
        self::assertSame('a&b', $result->deleted[0]->key);
        self::assertSame('v1', $result->deleted[0]->versionId);
    }

    public function testDeleteObjectsReturnsPerKeyErrors(): void
    {
        $http = new MockHttpClient(new Response(200, '<DeleteResult><Error><Key>locked</Key><VersionId>v9</VersionId><Code>AccessDenied</Code><Message>Object is WORM protected</Message></Error></DeleteResult>'));

        $result = $this->bucket($http)->deleteObjects([new ObjectIdentifier('locked', 'v9')]);

        self::assertSame([], $result->deleted);
        self::assertSame('locked', $result->errors[0]->key);
        self::assertSame('v9', $result->errors[0]->versionId);
        self::assertSame('AccessDenied', $result->errors[0]->code);
        self::assertSame('Object is WORM protected', $result->errors[0]->message);
    }

    public function testMoreThan1000DeletesAreRefusedAndNoneSendNothing(): void
    {
        $http = new MockHttpClient();
        $bucket = $this->bucket($http);

        self::assertSame([], $bucket->deleteObjects([])->deleted);

        $this->expectException(InvalidArgumentException::class);

        try {
            $bucket->deleteObjects(array_map(static fn(int $i): ObjectIdentifier => new ObjectIdentifier("k{$i}"), range(1, 1001)));
        } finally {
            self::assertSame(0, $http->callCount());
        }
    }

    public function testRetentionRoundTrip(): void
    {
        $http = new MockHttpClient(
            new Response(200),
            new Response(200, '<Retention><Mode>GOVERNANCE</Mode><RetainUntilDate>2026-11-01T11:00:00.000Z</RetainUntilDate></Retention>'),
        );
        $bucket = $this->bucket($http);

        $bucket->putRetention('k', new Retention(RetentionMode::Governance, new DateTimeImmutable('2026-11-01T11:00:00Z')), 'v1');
        $retention = $bucket->getRetention('k', 'v1');

        self::assertSame('http://127.0.0.1:9100/backups/k?retention=&versionId=v1', $http->requests[0]->uri);
        self::assertSame('<?xml version="1.0" encoding="UTF-8"?><Retention><Mode>GOVERNANCE</Mode><RetainUntilDate>2026-11-01T11:00:00Z</RetainUntilDate></Retention>', $http->requests[0]->body);
        self::assertArrayHasKey('Content-MD5', $http->requests[0]->headers);
        self::assertArrayNotHasKey('x-amz-bypass-governance-retention', $http->requests[0]->headers);
        self::assertSame(RetentionMode::Governance, $retention?->mode);
        self::assertSame('2026-11-01T11:00:00+00:00', $retention->retainUntil->format(\DATE_ATOM));
    }

    public function testMissingRetentionIsNull(): void
    {
        $bucket = $this->bucket(new MockHttpClient(
            new Response(404, '<Error><Code>NoSuchObjectLockConfiguration</Code></Error>'),
            // RustFS 1.0.1 answers an object without retention like this (measured 2026-10-18).
            new Response(200, '<?xml version="1.0" encoding="UTF-8"?><Retention></Retention>'),
        ));

        self::assertNull($bucket->getRetention('k'));
        self::assertNull($bucket->getRetention('k'));
    }

    public function testMultipartLifecycle(): void
    {
        $http = new MockHttpClient(
            new Response(200, '<InitiateMultipartUploadResult><Bucket>backups</Bucket><Key>big</Key><UploadId>up1</UploadId></InitiateMultipartUploadResult>'),
            new Response(200, '', ['etag' => '"p1"']),
            new Response(200, '<ListPartsResult><IsTruncated>false</IsTruncated><Part><PartNumber>1</PartNumber><ETag>"p1"</ETag><Size>5</Size><LastModified>2026-10-18T10:00:00Z</LastModified></Part></ListPartsResult>'),
            new Response(200, '<CompleteMultipartUploadResult><Key>big</Key><ETag>"whole-1"</ETag></CompleteMultipartUploadResult>', ['x-amz-version-id' => 'v7']),
            new Response(204),
        );
        $bucket = $this->bucket($http);

        $uploadId = $bucket->createMultipartUpload('big', 'application/octet-stream');
        $etag = $bucket->uploadPart('big', $uploadId, 1, 'hello');
        $parts = $bucket->listParts('big', $uploadId);
        $result = $bucket->completeMultipartUpload('big', $uploadId, [new Part(1, $etag)]);
        $bucket->abortMultipartUpload('big', $uploadId);

        self::assertSame('up1', $uploadId);
        self::assertSame('p1', $etag);
        self::assertSame('http://127.0.0.1:9100/backups/big?uploads=', $http->requests[0]->uri);
        self::assertSame('http://127.0.0.1:9100/backups/big?partNumber=1&uploadId=up1', $http->requests[1]->uri);
        self::assertSame(1, $parts->parts[0]->partNumber);
        self::assertSame(5, $parts->parts[0]->size);
        self::assertSame('<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"p1"</ETag></Part></CompleteMultipartUpload>', $http->requests[3]->body);
        self::assertSame('whole-1', $result->etag);
        self::assertSame('v7', $result->versionId);
        self::assertSame('DELETE', $http->requests[4]->method->value);
        self::assertSame('http://127.0.0.1:9100/backups/big?uploadId=up1', $http->requests[4]->uri);
    }

    public function testCompleteWithErrorBodyRaises(): void
    {
        $http = new MockHttpClient(new Response(200, '<Error><Code>InternalError</Code><Message>We encountered an internal error.</Message></Error>'));

        $this->expectException(ApiException::class);

        $this->bucket($http)->completeMultipartUpload('big', 'up1', [new Part(1, 'p1')]);
    }

    public function testPartNumbersStayWithinS3Limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->bucket(new MockHttpClient())->uploadPart('big', 'up1', 10001, 'x');
    }

    public function testPresignedUrlMatchesTheApi(): void
    {
        $url = $this->bucket(new MockHttpClient())->presignedGetUrl('downloads/wpaas/abc/Backup "Haupt" ä.sql', 60, downloadName: 'Backup "Haupt" ä.sql');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertStringStartsWith('http://127.0.0.1:9100/backups/downloads/wpaas/abc/Backup%20%22Haupt%22%20%C3%A4.sql?', $url);
        self::assertSame('attachment; filename="Backup _Haupt_ _.sql"; filename*=UTF-8\'\'Backup%20%22Haupt%22%20%C3%A4.sql', $query['response-content-disposition'] ?? null);
        self::assertSame('60', $query['X-Amz-Expires'] ?? null);
        self::assertSame('20261018T120000Z', $query['X-Amz-Date'] ?? null);
        self::assertArrayNotHasKey('versionId', $query);
    }

    public function testPresignedUrlOfAVersion(): void
    {
        parse_str((string) parse_url($this->bucket(new MockHttpClient())->presignedGetUrl('k', 3600, 'v1'), \PHP_URL_QUERY), $query);

        self::assertSame('v1', $query['versionId'] ?? null);
    }

    public function testPresignedLimits(): void
    {
        $bucket = $this->bucket(new MockHttpClient());
        $bucket->presignedGetUrl('k', 604800);

        $this->expectException(InvalidArgumentException::class);

        $bucket->presignedGetUrl('k', 604801);
    }

    private function bucket(MockHttpClient $http): Bucket
    {
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-18T12:00:00Z');
            }
        };

        return new S3('http://127.0.0.1:9100', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'secret', options: new ClientOptions(retryBaseDelay: 0.0), httpClient: $http, clock: $clock)->bucket('backups');
    }
}
