# Operations

## Signing

Every request is signed with Signature Version 4: the SHA-256 of the body as
payload hash, `x-amz-date` from the `Clock`, the headers `Host`, `User-Agent`,
`x-amz-*` and any given header. Path segments are percent-encoded (RFC 3986) with
`/` kept; query parameters are encoded and sorted by encoded name. The tests pin
the five worked examples of AWS's documentation (2013-05-24): GET with `Range`,
PUT, lifecycle, list and a presigned GET.

## Reading answers

XML is read with `LIBXML_NONET`; external entities are not resolved. An answer
must have the expected root element; an `<Error>` raises even with HTTP 200.
Times are UTC, ETags lose their quotes.

## Listings

`objects()` follows `NextContinuationToken`, `versions()` follows
`NextKeyMarker`/`NextVersionIdMarker`; both stop when the next value is missing,
even if `IsTruncated` is true, so a broken server cannot cause an endless loop.

## Writes

`putObject()`, `uploadPart()`, `putRetention()` and `deleteObjects()` send
`Content-MD5`; S3 requires it for Multi-Object Delete and for retention. Keys in
XML bodies are escaped. `deleteObjects()` refuses more than 1000 entries before
sending and sends nothing for an empty list.

## Retention and bypass

`putObject(retention:)` sets `x-amz-object-lock-mode` and
`x-amz-object-lock-retain-until-date` (UTC). `getRetention()` is null for a
version without retention, whether the server says so with 404
`NoSuchObjectLockConfiguration` (AWS) or an empty `<Retention/>` (RustFS 1.0.1).
The bypass header goes out only with `bypassGovernanceRetention: true`.

## Presigned URLs

Valid for 1 to 604800 seconds (a week, S3's limit); outside that range
`InvalidArgumentException`. With `downloadName`, `response-content-disposition`
is `attachment; filename="<ASCII fallback>"; filename*=UTF-8''<encoded name>`.

## Retries

Network failures and 5xx for `GET`, `HEAD`, `PUT`, `DELETE` with exponential
backoff; never `POST`. `429` and `503 SlowDown` only with `retryOnThrottle`.
