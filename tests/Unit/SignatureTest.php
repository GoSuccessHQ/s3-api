<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Unit;

use DateTimeImmutable;
use GoSuccess\S3\Signature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The examples of AWS's documentation "Signature Calculations for the Authorization
 * Header" and "Authenticating Requests: Using Query Parameters" (credentials of the
 * documentation, us-east-1, 2013-05-24, bucket examplebucket).
 */
#[CoversClass(Signature::class)]
final class SignatureTest extends TestCase
{
    private const string HOST = 'examplebucket.s3.amazonaws.com';

    private const string EMPTY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testGetObjectWithRange(): void
    {
        $headers = $this->signature()->headersFor('GET', '/test.txt', [], ['Host' => self::HOST, 'Range' => 'bytes=0-9'], self::EMPTY, $this->now());

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['Authorization'],
        );
        self::assertSame('20130524T000000Z', $headers['x-amz-date']);
        self::assertSame(self::EMPTY, $headers['x-amz-content-sha256']);
    }

    public function testPutObject(): void
    {
        $body = 'Welcome to Amazon S3.';
        $headers = $this->signature()->headersFor('PUT', '/test$file.text', [], ['Host' => self::HOST, 'Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY'], Signature::hashPayload($body), $this->now());

        self::assertStringEndsWith('SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $headers['Authorization']);
    }

    public function testGetBucketLifecycle(): void
    {
        $headers = $this->signature()->headersFor('GET', '/', ['lifecycle' => ''], ['Host' => self::HOST], self::EMPTY, $this->now());

        self::assertStringEndsWith('Signature=fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', $headers['Authorization']);
    }

    public function testListObjects(): void
    {
        $headers = $this->signature()->headersFor('GET', '/', ['prefix' => 'J', 'max-keys' => '2'], ['Host' => self::HOST], self::EMPTY, $this->now());

        self::assertStringEndsWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $headers['Authorization']);
    }

    public function testPresignedGet(): void
    {
        $query = $this->signature()->presignedQuery('GET', '/test.txt', self::HOST, 86400, [], $this->now());

        self::assertSame('aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $query['X-Amz-Signature']);
        self::assertSame('AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request', $query['X-Amz-Credential']);
        self::assertSame('86400', $query['X-Amz-Expires']);
        self::assertSame('host', $query['X-Amz-SignedHeaders']);
    }

    public function testEncodesPathSegmentsAndKeepsSlashes(): void
    {
        self::assertSame('/bucket/a%20b%2Bc%25/%C3%BC//x.txt', Signature::encodePath('/bucket/a b+c%/ü//x.txt'));
    }

    public function testEncodesAndSortsTheQuery(): void
    {
        self::assertSame('list-type=2&prefix=a%2Fb%3Dc&versions=', Signature::encodeQuery(['versions' => '', 'prefix' => 'a/b=c', 'list-type' => '2']));
    }

    public function testATimeInAnotherZoneIsSignedAsUtc(): void
    {
        $berlin = new DateTimeImmutable('2013-05-24T02:00:00+02:00');
        $headers = $this->signature()->headersFor('GET', '/test.txt', [], ['Host' => self::HOST, 'Range' => 'bytes=0-9'], self::EMPTY, $berlin);

        self::assertStringEndsWith('Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $headers['Authorization']);
    }

    public function testTheSecretIsNotInDebugOutput(): void
    {
        self::assertStringNotContainsString('EXAMPLEKEY', print_r($this->signature(), true));
    }

    private function signature(): Signature
    {
        return new Signature('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2013-05-24T00:00:00Z');
    }
}
