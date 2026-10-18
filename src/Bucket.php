<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Exception\SerializationException;
use GoSuccess\S3\Exception\TransportException;
use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\Method;
use GoSuccess\S3\Http\Response;
use GoSuccess\S3\Model\DeleteError;
use GoSuccess\S3\Model\DeleteMarker;
use GoSuccess\S3\Model\DeleteObjectsResult;
use GoSuccess\S3\Model\DeleteResult;
use GoSuccess\S3\Model\ListObjectsPage;
use GoSuccess\S3\Model\ListVersionsPage;
use GoSuccess\S3\Model\ObjectHead;
use GoSuccess\S3\Model\ObjectIdentifier;
use GoSuccess\S3\Model\ObjectLockConfiguration;
use GoSuccess\S3\Model\ObjectSummary;
use GoSuccess\S3\Model\ObjectVersion;
use GoSuccess\S3\Model\Part;
use GoSuccess\S3\Model\PartsPage;
use GoSuccess\S3\Model\PutResult;
use GoSuccess\S3\Model\Retention;
use GoSuccess\S3\Model\RetentionMode;
use GoSuccess\S3\Model\S3Object;
use GoSuccess\S3\Model\Versioning;
use GoSuccess\S3\Model\VersioningStatus;
use InvalidArgumentException;
use LogicException;

/**
 * The operations on one bucket.
 */
final readonly class Bucket
{
    /** S3 deletes at most this many objects per request. */
    public const int MAX_DELETE = 1000;

    /** The highest part number of a multipart upload. */
    public const int MAX_PARTS = 10000;

    /** A presigned URL is valid for at most a week. */
    public const int MAX_PRESIGNED_SECONDS = 604800;

    /** At most this much of an error answer is read back from a download file. */
    private const int ERROR_BODY_BYTES = 65536;

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

    /**
     * The versioning configuration (`GET ?versioning`).
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function versioning(): Versioning
    {
        $xml = Xml::parse($this->connection->send(Method::Get, $this->name, query: ['versioning' => '']), 'VersioningConfiguration', $this->connection);

        return new Versioning(VersioningStatus::tryFrom(Xml::text($xml->Status) ?? ''));
    }

    /**
     * The Object Lock configuration (`GET ?object-lock`); null when the bucket has none.
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function objectLock(): ?ObjectLockConfiguration
    {
        $response = $this->connection->send(Method::Get, $this->name, query: ['object-lock' => '']);

        if ($response->statusCode === 404 && Xml::text(Xml::load($response->body)?->Code) === 'ObjectLockConfigurationNotFoundError') {
            return null;
        }

        $xml = Xml::parse($response, 'ObjectLockConfiguration', $this->connection);
        $retention = $xml->Rule?->DefaultRetention;
        $days = Xml::text($retention?->Days);
        $years = Xml::text($retention?->Years);

        return new ObjectLockConfiguration(
            enabled: Xml::text($xml->ObjectLockEnabled) === 'Enabled',
            mode: RetentionMode::tryFrom(Xml::text($retention?->Mode) ?? ''),
            days: $days === null ? null : (int) $days,
            years: $years === null ? null : (int) $years,
        );
    }

    /**
     * One page of the objects (ListObjectsV2).
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function listObjects(string $prefix = '', ?string $continuationToken = null, int $maxKeys = 1000, ?string $delimiter = null): ListObjectsPage
    {
        $query = ['list-type' => '2', 'max-keys' => (string) $maxKeys];

        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }

        if ($continuationToken !== null) {
            $query['continuation-token'] = $continuationToken;
        }

        if ($delimiter !== null) {
            $query['delimiter'] = $delimiter;
        }

        $xml = Xml::parse($this->connection->send(Method::Get, $this->name, query: $query), 'ListBucketResult', $this->connection);
        $objects = [];
        $prefixes = [];

        foreach ($xml->Contents as $entry) {
            $objects[] = new ObjectSummary(
                key: Xml::required($entry->Key, 'Key'),
                size: (int) Xml::text($entry->Size),
                etag: Xml::etag(Xml::text($entry->ETag)),
                lastModified: Xml::date($entry->LastModified, 'LastModified'),
                storageClass: Xml::text($entry->StorageClass),
            );
        }

        foreach ($xml->CommonPrefixes as $common) {
            $prefixes[] = Xml::required($common->Prefix, 'Prefix');
        }

        return new ListObjectsPage($objects, $prefixes, Xml::bool($xml->IsTruncated), Xml::text($xml->NextContinuationToken));
    }

    /**
     * Every object under the prefix, page by page; ends when S3 sends no next token.
     *
     * @return Generator<int, ObjectSummary>
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function objects(string $prefix = ''): Generator
    {
        $token = null;

        do {
            $page = $this->listObjects($prefix, $token);

            yield from $page->objects;

            $token = $page->nextContinuationToken;
        } while ($page->isTruncated && $token !== null);
    }

    /**
     * One page of the versions and delete markers (ListObjectVersions).
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function listVersions(string $prefix = '', ?string $keyMarker = null, ?string $versionIdMarker = null, int $maxKeys = 1000): ListVersionsPage
    {
        $query = ['versions' => '', 'max-keys' => (string) $maxKeys];

        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }

        if ($keyMarker !== null) {
            $query['key-marker'] = $keyMarker;
        }

        if ($versionIdMarker !== null) {
            $query['version-id-marker'] = $versionIdMarker;
        }

        $xml = Xml::parse($this->connection->send(Method::Get, $this->name, query: $query), 'ListVersionsResult', $this->connection);
        $entries = [];

        foreach ($xml->children() as $name => $entry) {
            if ($name === 'Version') {
                $entries[] = new ObjectVersion(
                    key: Xml::required($entry->Key, 'Key'),
                    versionId: Xml::required($entry->VersionId, 'VersionId'),
                    isLatest: Xml::bool($entry->IsLatest),
                    size: (int) Xml::text($entry->Size),
                    etag: Xml::etag(Xml::text($entry->ETag)),
                    lastModified: Xml::date($entry->LastModified, 'LastModified'),
                );
            } elseif ($name === 'DeleteMarker') {
                $entries[] = new DeleteMarker(
                    key: Xml::required($entry->Key, 'Key'),
                    versionId: Xml::required($entry->VersionId, 'VersionId'),
                    isLatest: Xml::bool($entry->IsLatest),
                    lastModified: Xml::date($entry->LastModified, 'LastModified'),
                );
            }
        }

        return new ListVersionsPage($entries, Xml::bool($xml->IsTruncated), Xml::text($xml->NextKeyMarker), Xml::text($xml->NextVersionIdMarker));
    }

    /**
     * Every version and delete marker under the prefix, page by page; ends when
     * S3 sends no next marker.
     *
     * @return Generator<int, ObjectVersion|DeleteMarker>
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function versions(string $prefix = ''): Generator
    {
        $keyMarker = null;
        $versionIdMarker = null;

        do {
            $page = $this->listVersions($prefix, $keyMarker, $versionIdMarker);

            yield from $page->entries;

            $keyMarker = $page->nextKeyMarker;
            $versionIdMarker = $page->nextVersionIdMarker;
        } while ($page->isTruncated && ($keyMarker !== null || $versionIdMarker !== null));
    }

    /**
     * An object's content and headers (`GET`).
     *
     * @throws ApiException (e.g. NotFoundException for a missing key)|TransportException
     */
    public function getObject(string $key, ?string $versionId = null): S3Object
    {
        $response = $this->connection->send(Method::Get, $this->name, $key, self::version($versionId));

        if (!$response->isSuccessful) {
            throw $this->connection->failure($response);
        }

        return new S3Object($response->body, ObjectHead::fromResponse($response));
    }

    /**
     * Writes an object's content into the file at `$path` without holding it in
     * memory; needs a {@see \GoSuccess\S3\Http\StreamingHttpClient}. On an error
     * the file is removed.
     *
     * @throws ApiException|TransportException|LogicException without a streaming transport
     */
    public function getObjectToFile(string $key, string $path, ?string $versionId = null): ObjectHead
    {
        $response = $this->connection->sendToFile($this->name, $key, self::version($versionId), $path);

        if ($response->isSuccessful) {
            return ObjectHead::fromResponse($response);
        }

        $body = @file_get_contents($path, false, null, 0, self::ERROR_BODY_BYTES);
        @unlink($path);

        throw $this->connection->failure(new Response($response->statusCode, \is_string($body) ? $body : '', $response->headers, $response->reasonPhrase));
    }

    /**
     * An object's headers (`HEAD`); null when the key (or version) does not exist.
     *
     * @throws ApiException|TransportException
     */
    public function headObject(string $key, ?string $versionId = null): ?ObjectHead
    {
        $response = $this->connection->send(Method::Head, $this->name, $key, self::version($versionId));

        return match (true) {
            $response->isSuccessful => ObjectHead::fromResponse($response),
            $response->statusCode === 404 => null,
            default => throw $this->connection->failure($response),
        };
    }

    /**
     * Writes an object (`PUT`, with `Content-MD5`), optionally with a retention.
     *
     * @param array<string, string> $metadata Sent as `x-amz-meta-<name>`.
     *
     * @throws ApiException|TransportException
     */
    public function putObject(string $key, string $body, ?string $contentType = null, array $metadata = [], ?Retention $retention = null): PutResult
    {
        $headers = ['Content-MD5' => base64_encode(md5($body, true))];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        foreach ($metadata as $name => $value) {
            $headers["x-amz-meta-{$name}"] = $value;
        }

        if ($retention !== null) {
            $headers['x-amz-object-lock-mode'] = $retention->mode->value;
            $headers['x-amz-object-lock-retain-until-date'] = self::time($retention->retainUntil);
        }

        $response = $this->connection->send(Method::Put, $this->name, $key, [], $body, $headers);

        if (!$response->isSuccessful) {
            throw $this->connection->failure($response);
        }

        return new PutResult(trim($response->header('etag'), '" '), self::optional($response->header('x-amz-version-id')));
    }

    /**
     * Deletes an object or a version. Never bypasses a governance retention
     * unless `bypassGovernanceRetention` is set; GoSuccess uses it only to find
     * out whether a key could, which is then reported as a problem.
     *
     * @throws ApiException (e.g. ForbiddenException for a retained version)|TransportException
     */
    public function deleteObject(string $key, ?string $versionId = null, bool $bypassGovernanceRetention = false): DeleteResult
    {
        $response = $this->connection->send(Method::Delete, $this->name, $key, self::version($versionId), '', self::bypass($bypassGovernanceRetention));

        if (!$response->isSuccessful) {
            throw $this->connection->failure($response);
        }

        return new DeleteResult(self::optional($response->header('x-amz-version-id')), $response->header('x-amz-delete-marker') === 'true');
    }

    /**
     * Deletes up to 1000 keys or versions in one request (`POST ?delete`, with
     * `Content-MD5`, which S3 requires here); per-key failures come back in the
     * result. An empty list sends nothing.
     *
     * @param list<ObjectIdentifier> $objects
     *
     * @throws InvalidArgumentException for more than 1000, before any request
     * @throws ApiException|TransportException|SerializationException
     */
    public function deleteObjects(array $objects, bool $quiet = false, bool $bypassGovernanceRetention = false): DeleteObjectsResult
    {
        if ($objects === []) {
            return new DeleteObjectsResult([], []);
        }

        if (\count($objects) > self::MAX_DELETE) {
            throw new InvalidArgumentException('S3 deletes at most ' . self::MAX_DELETE . ' objects per request.');
        }

        $body = '<?xml version="1.0" encoding="UTF-8"?><Delete>' . ($quiet ? '<Quiet>true</Quiet>' : '');

        foreach ($objects as $object) {
            $body .= '<Object><Key>' . Xml::escape($object->key) . '</Key>'
                . ($object->versionId === null ? '' : '<VersionId>' . Xml::escape($object->versionId) . '</VersionId>')
                . '</Object>';
        }

        $body .= '</Delete>';
        $headers = ['Content-Type' => 'application/xml', 'Content-MD5' => base64_encode(md5($body, true))] + self::bypass($bypassGovernanceRetention);
        $xml = Xml::parse($this->connection->send(Method::Post, $this->name, '', ['delete' => ''], $body, $headers), 'DeleteResult', $this->connection);
        $deleted = [];
        $errors = [];

        foreach ($xml->Deleted as $entry) {
            $deleted[] = new ObjectIdentifier(Xml::required($entry->Key, 'Key'), Xml::text($entry->VersionId));
        }

        foreach ($xml->Error as $entry) {
            $errors[] = new DeleteError(Xml::required($entry->Key, 'Key'), Xml::text($entry->VersionId), Xml::text($entry->Code) ?? 'Unknown', Xml::text($entry->Message));
        }

        return new DeleteObjectsResult($deleted, $errors);
    }

    /**
     * The retention of an object version (`GET ?retention`); null when it has none.
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function getRetention(string $key, ?string $versionId = null): ?Retention
    {
        $response = $this->connection->send(Method::Get, $this->name, $key, ['retention' => ''] + self::version($versionId));

        if ($response->statusCode === 404 && Xml::text(Xml::load($response->body)?->Code) === 'NoSuchObjectLockConfiguration') {
            return null;
        }

        $xml = Xml::parse($response, 'Retention', $this->connection);

        // RustFS answers a version without retention with an empty <Retention/> and HTTP 200.
        if (Xml::text($xml->Mode) === null && Xml::text($xml->RetainUntilDate) === null) {
            return null;
        }

        $mode = RetentionMode::tryFrom(Xml::text($xml->Mode) ?? '') ?? throw new SerializationException('The S3 answer has an unknown retention mode.');

        return new Retention($mode, Xml::date($xml->RetainUntilDate, 'RetainUntilDate'));
    }

    /**
     * Sets the retention of an object version (`PUT ?retention`).
     *
     * @throws ApiException|TransportException
     */
    public function putRetention(string $key, Retention $retention, ?string $versionId = null, bool $bypassGovernanceRetention = false): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Retention><Mode>' . $retention->mode->value . '</Mode><RetainUntilDate>' . self::time($retention->retainUntil) . '</RetainUntilDate></Retention>';
        $headers = ['Content-Type' => 'application/xml', 'Content-MD5' => base64_encode(md5($body, true))] + self::bypass($bypassGovernanceRetention);
        $response = $this->connection->send(Method::Put, $this->name, $key, ['retention' => ''] + self::version($versionId), $body, $headers);

        if (!$response->isSuccessful) {
            throw $this->connection->failure($response);
        }
    }

    /**
     * Starts a multipart upload and returns its ID (`POST ?uploads`).
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function createMultipartUpload(string $key, ?string $contentType = null): string
    {
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];
        $xml = Xml::parse($this->connection->send(Method::Post, $this->name, $key, ['uploads' => ''], '', $headers), 'InitiateMultipartUploadResult', $this->connection);

        return Xml::required($xml->UploadId, 'UploadId');
    }

    /**
     * Uploads a part (1 to 10000) and returns its ETag without quotes.
     *
     * @throws InvalidArgumentException for a part number outside 1 to 10000
     * @throws ApiException|TransportException
     */
    public function uploadPart(string $key, string $uploadId, int $partNumber, string $body): string
    {
        if ($partNumber < 1 || $partNumber > self::MAX_PARTS) {
            throw new InvalidArgumentException('A part number lies between 1 and ' . self::MAX_PARTS . '.');
        }

        $response = $this->connection->send(Method::Put, $this->name, $key, ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId], $body, ['Content-MD5' => base64_encode(md5($body, true))]);

        if (!$response->isSuccessful) {
            throw $this->connection->failure($response);
        }

        return trim($response->header('etag'), '" ');
    }

    /**
     * Completes a multipart upload; an `<Error>` S3 sends with HTTP 200 raises.
     *
     * @param list<Part> $parts In ascending part number.
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): PutResult
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';

        foreach ($parts as $part) {
            $body .= '<Part><PartNumber>' . $part->partNumber . '</PartNumber><ETag>"' . Xml::escape($part->etag) . '"</ETag></Part>';
        }

        $body .= '</CompleteMultipartUpload>';
        $response = $this->connection->send(Method::Post, $this->name, $key, ['uploadId' => $uploadId], $body, ['Content-Type' => 'application/xml']);
        $xml = Xml::parse($response, 'CompleteMultipartUploadResult', $this->connection);

        return new PutResult(Xml::etag(Xml::text($xml->ETag)), self::optional($response->header('x-amz-version-id')));
    }

    /**
     * Abandons a multipart upload and its parts (`DELETE ?uploadId`).
     *
     * @throws ApiException|TransportException
     */
    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        $response = $this->connection->send(Method::Delete, $this->name, $key, ['uploadId' => $uploadId]);

        if (!$response->isSuccessful) {
            throw $this->connection->failure($response);
        }
    }

    /**
     * The uploaded parts of a multipart upload (`GET ?uploadId`), one page.
     *
     * @throws ApiException|TransportException|SerializationException
     */
    public function listParts(string $key, string $uploadId, ?int $partNumberMarker = null): PartsPage
    {
        $query = ['uploadId' => $uploadId] + ($partNumberMarker === null ? [] : ['part-number-marker' => (string) $partNumberMarker]);
        $xml = Xml::parse($this->connection->send(Method::Get, $this->name, $key, $query), 'ListPartsResult', $this->connection);
        $parts = [];

        foreach ($xml->Part as $part) {
            $parts[] = new Part(
                partNumber: (int) Xml::required($part->PartNumber, 'PartNumber'),
                etag: Xml::etag(Xml::text($part->ETag)),
                size: Xml::text($part->Size) === null ? null : (int) Xml::text($part->Size),
                lastModified: Xml::text($part->LastModified) === null ? null : Xml::date($part->LastModified, 'LastModified'),
            );
        }

        $marker = Xml::text($xml->NextPartNumberMarker);

        return new PartsPage($parts, Xml::bool($xml->IsTruncated), $marker === null ? null : (int) $marker);
    }

    /**
     * A presigned URL to download the object for `$seconds` (1 to 604800, a week).
     * With `$downloadName`, S3 sends it as an attachment of that name.
     *
     * @throws InvalidArgumentException for a validity S3 does not accept
     */
    public function presignedGetUrl(string $key, int $seconds, ?string $versionId = null, ?string $downloadName = null): string
    {
        if ($seconds < 1 || $seconds > self::MAX_PRESIGNED_SECONDS) {
            throw new InvalidArgumentException('A presigned URL is valid for 1 to ' . self::MAX_PRESIGNED_SECONDS . ' seconds.');
        }

        $query = self::version($versionId);

        if ($downloadName !== null) {
            // An ASCII fallback for old clients, the full name per RFC 6266 / 5987.
            $ascii = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/u', '_', $downloadName);
            $query['response-content-disposition'] = "attachment; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($downloadName);
        }

        return $this->connection->presignedUrl($this->name, $key, $seconds, $query);
    }

    /**
     * @return array<string, string>
     */
    private static function version(?string $versionId): array
    {
        return $versionId === null ? [] : ['versionId' => $versionId];
    }

    /**
     * @return array<string, string>
     */
    private static function bypass(bool $bypass): array
    {
        return $bypass ? ['x-amz-bypass-governance-retention' => 'true'] : [];
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function optional(string $value): ?string
    {
        return $value === '' || $value === 'null' ? null : $value;
    }
}
