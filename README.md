# S3 API for PHP

A modern, strongly-typed, dependency-free PHP client for S3-compatible object
storage: AWS S3, Impossible Cloud, RustFS, MinIO and others that speak the S3
protocol with Signature Version 4.

## Features

- **Signature V4** for headers and presigned URLs, verified against the worked
  examples of AWS's documentation.
- **Path or virtual-host style** addressing.
- **Buckets**: existence, versioning, Object Lock configuration, listings of
  objects and versions over all pages.
- **Objects**: read (also straight into a file), head, write (always with
  `Content-MD5`), delete, multi-object delete, retention, multipart uploads,
  presigned download links.
- **Safe defaults**: retries only where a request may run twice, XML without
  external entities, never a governance bypass unless asked for.
- **No dependencies** beyond `ext-curl`, `ext-json`, `ext-simplexml` and
  `ext-libxml`.

## Requirements

- PHP 8.4 or newer
- `ext-curl`, `ext-json`, `ext-simplexml`, `ext-libxml`

## Installation

```bash
composer require gosuccess/s3-api
```

## Quick start

```php
use GoSuccess\S3\S3;

$s3 = new S3('https://eu-central-2.storage.impossibleapi.net', 'eu-central-2', $accessKey, $secretKey);
$bucket = $s3->bucket('backups');

$bucket->putObject('reports/2026-10.csv', $csv, 'text/csv');

foreach ($bucket->objects('reports/') as $object) {
    echo "{$object->key} {$object->size}\n";
}

$link = $bucket->presignedGetUrl('reports/2026-10.csv', 3600, downloadName: 'Report October.csv');
```

Path style (`https://host/bucket/key`, the default) works with every server;
pass `pathStyle: false` for virtual-host style (`https://bucket.host/key`).
Keys are taken literally: for S3, `/a` and `a` are different objects.

## Operations

| Method | S3 |
|---|---|
| `exists()` | `HEAD` bucket; false for 404 |
| `versioning()` | `GET ?versioning` |
| `objectLock()` | `GET ?object-lock`; null when the bucket has none |
| `listObjects()`, `objects()` | ListObjectsV2, one page or all |
| `listVersions()`, `versions()` | ListObjectVersions with delete markers, one page or all |
| `getObject()`, `getObjectToFile()`, `headObject()` | `GET`, `GET` into a file, `HEAD` (null for 404) |
| `putObject()` | `PUT` with `Content-MD5`, metadata, optional retention |
| `deleteObject()` | `DELETE`, optionally a version |
| `deleteObjects()` | Multi-Object Delete, up to 1000, per-key errors in the result |
| `getRetention()`, `putRetention()` | `GET`/`PUT ?retention` |
| `createMultipartUpload()`, `uploadPart()`, `listParts()`, `completeMultipartUpload()`, `abortMultipartUpload()` | multipart uploads |
| `presignedGetUrl()` | presigned `GET`, 1 s to a week, optionally as an attachment |

Listings walk every page and end when the server sends no next token or
marker, also when it claims truncation without one.

### Governance bypass

`deleteObject()`, `deleteObjects()` and `putRetention()` send
`x-amz-bypass-governance-retention: true` only with
`bypassGovernanceRetention: true`. A backup system should never need it; it
exists so that a check can find out whether a key *could* bypass a lock, and
report that as a problem.

## Errors and retries

Every exception implements `GoSuccess\S3\Exception\S3Exception`. Error responses
become `ApiException` subclasses by status (`NotFoundException`,
`ForbiddenException`, `ServerException`, …) with S3's `<Code>` in `errorCode`
and `<RequestId>` in `requestId`. An `<Error>` that arrives with HTTP 200 (as
CompleteMultipartUpload may send) raises as well. A body that is not the expected
XML raises `SerializationException`.

Network failures and 5xx are retried with backoff for `GET`, `HEAD`, `PUT` and
`DELETE`, never for `POST` (multipart, multi-delete). A throttle (`429`, `503
SlowDown`) is retried only with `ClientOptions(retryOnThrottle: true)`.

## Configuration and custom transport

`ClientOptions` sets timeouts (60 s), retries (2), backoff, user agent and the
throttle retry. A custom transport implements `GoSuccess\S3\Http\HttpClient`, or
`StreamingHttpClient` for `getObjectToFile()`. A `Clock` sets the signing time
(for tests).

## Tested against

RustFS 1.0.1 on 2026-10-18 (`composer rustfs:start`): every operation above,
keys with spaces, `+`, `%`, umlauts and query characters, versions and delete
markers, Object Lock with a retained version that cannot be deleted without
bypass. Two RustFS quirks the client copes with: it strips leading slashes from
keys (`//a` is stored as `a`), and it answers a version without retention with
an empty `<Retention/>` and HTTP 200 instead of `404
NoSuchObjectLockConfiguration`; `getRetention()` returns null for both.

## Not covered yet

Creating and deleting buckets, bucket policies, lifecycle, CORS, tagging, ACLs,
legal hold, server-side encryption settings, replication, `CopyObject`,
`SelectObjectContent`, checksums other than MD5 and SHA-256, website hosting,
and uploads from a file stream. See [docs/not-covered.md](docs/not-covered.md).

## Development

```bash
composer check                                                   # style, analysis, unit tests
composer rustfs:start
S3_TEST_ENDPOINT=http://127.0.0.1:9100 composer test:integration
composer rustfs:stop
```

## License

MIT
