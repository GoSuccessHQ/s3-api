<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;

/**
 * AWS Signature Version 4 for S3, as headers or as a presigned query.
 *
 * Paths are encoded per segment (`/` stays), query parameters are encoded and
 * sorted by their encoded name, header names lower-cased and sorted; the
 * payload hash is signed as given (SHA-256 of the body, or `UNSIGNED-PAYLOAD`).
 */
final readonly class Signature
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public const string UNSIGNED = 'UNSIGNED-PAYLOAD';

    public function __construct(
        private string $accessKey,
        #[SensitiveParameter]
        private string $secretKey,
        private string $region,
        private string $service = 's3',
    ) {}

    /**
     * The headers with `x-amz-date`, `x-amz-content-sha256` and `Authorization` added.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers Every header to sign, including `Host`.
     *
     * @return array<string, string>
     */
    public function headersFor(string $method, string $path, array $query, array $headers, string $payloadHash, DateTimeImmutable $now): array
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $utc->format('Ymd\THis\Z');
        $day = $utc->format('Ymd');

        $headers['x-amz-date'] = $amzDate;
        $headers['x-amz-content-sha256'] = $payloadHash;

        $signedHeaders = $this->signedHeaders($headers);
        $canonical = $this->canonicalRequest($method, $path, $query, $this->canonicalHeaders($headers), $signedHeaders, $payloadHash);
        $scope = "{$day}/{$this->region}/{$this->service}/aws4_request";
        $signature = $this->sign($this->stringToSign($amzDate, $scope, $canonical), $day);

        $headers['Authorization'] = self::ALGORITHM
            . " Credential={$this->accessKey}/{$scope},"
            . " SignedHeaders={$signedHeaders},"
            . " Signature={$signature}";

        return $headers;
    }

    /**
     * The query of a presigned URL valid for `$seconds`, signing only the host.
     *
     * @param array<string, string> $query Further parameters to sign, e.g. `response-content-disposition`.
     *
     * @return array<string, string>
     */
    public function presignedQuery(string $method, string $path, string $host, int $seconds, array $query, DateTimeImmutable $now): array
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $utc->format('Ymd\THis\Z');
        $day = $utc->format('Ymd');
        $scope = "{$day}/{$this->region}/{$this->service}/aws4_request";

        $query['X-Amz-Algorithm'] = self::ALGORITHM;
        $query['X-Amz-Credential'] = "{$this->accessKey}/{$scope}";
        $query['X-Amz-Date'] = $amzDate;
        $query['X-Amz-Expires'] = (string) $seconds;
        $query['X-Amz-SignedHeaders'] = 'host';

        $canonical = $this->canonicalRequest($method, $path, $query, "host:{$host}\n", 'host', self::UNSIGNED);
        $query['X-Amz-Signature'] = $this->sign($this->stringToSign($amzDate, $scope, $canonical), $day);

        return $query;
    }

    /**
     * Each segment of the path percent-encoded (RFC 3986), the slashes kept.
     */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * The canonical query: names and values encoded, sorted by encoded name.
     *
     * @param array<string, string> $query
     */
    public static function encodeQuery(array $query): string
    {
        $encoded = [];

        foreach ($query as $name => $value) {
            $encoded[rawurlencode((string) $name)] = rawurlencode($value);
        }

        ksort($encoded, \SORT_STRING);

        return implode('&', array_map(static fn(string $name, string $value): string => "{$name}={$value}", array_keys($encoded), $encoded));
    }

    /**
     * SHA-256 of a payload, hex.
     */
    public static function hashPayload(string $payload): string
    {
        return hash('sha256', $payload);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['accessKey' => $this->accessKey, 'secretKey' => '***', 'region' => $this->region, 'service' => $this->service];
    }

    /**
     * @param array<string, string> $query
     */
    private function canonicalRequest(string $method, string $path, array $query, string $canonicalHeaders, string $signedHeaders, string $payloadHash): string
    {
        return implode("\n", [strtoupper($method), self::encodePath($path), self::encodeQuery($query), $canonicalHeaders, $signedHeaders, $payloadHash]);
    }

    private function stringToSign(string $amzDate, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function canonicalHeaders(array $headers): string
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[strtolower(trim($name))] = trim($value);
        }

        ksort($lines, \SORT_STRING);

        return implode('', array_map(static fn(string $name, string $value): string => "{$name}:{$value}\n", array_keys($lines), $lines));
    }

    /**
     * @param array<string, string> $headers
     */
    private function signedHeaders(array $headers): string
    {
        $names = array_map(static fn(string $name): string => strtolower(trim($name)), array_keys($headers));
        sort($names, \SORT_STRING);

        return implode(';', $names);
    }

    private function sign(string $stringToSign, string $day): string
    {
        $key = hash_hmac('sha256', $day, "AWS4{$this->secretKey}", true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return hash_hmac('sha256', $stringToSign, $key);
    }
}
