<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use Generator;
use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Exception\SerializationException;
use GoSuccess\S3\Exception\TransportException;
use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\Method;
use GoSuccess\S3\Model\DeleteMarker;
use GoSuccess\S3\Model\ListObjectsPage;
use GoSuccess\S3\Model\ListVersionsPage;
use GoSuccess\S3\Model\ObjectLockConfiguration;
use GoSuccess\S3\Model\ObjectSummary;
use GoSuccess\S3\Model\ObjectVersion;
use GoSuccess\S3\Model\RetentionMode;
use GoSuccess\S3\Model\Versioning;
use GoSuccess\S3\Model\VersioningStatus;

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
}
