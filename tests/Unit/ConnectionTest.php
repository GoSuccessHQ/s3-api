<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Unit;

use DateTimeImmutable;
use GoSuccess\S3\Addressing;
use GoSuccess\S3\ClientOptions;
use GoSuccess\S3\Clock;
use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Exception\NotFoundException;
use GoSuccess\S3\Exception\SerializationException;
use GoSuccess\S3\Exception\TransportException;
use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\Method;
use GoSuccess\S3\Http\Response;
use GoSuccess\S3\S3;
use GoSuccess\S3\Signature;
use GoSuccess\S3\Tests\Support\MockHttpClient;
use GoSuccess\S3\Xml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Connection::class)]
#[CoversClass(Xml::class)]
#[CoversClass(S3::class)]
final class ConnectionTest extends TestCase
{
    private const string SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    public function testSignsAndSendsToThePathStyleUrl(): void
    {
        $http = new MockHttpClient(new Response(200, 'ok'));

        $this->connection($http)->send(Method::Put, 'backups', 'a b/ü.txt', ['versionId' => 'v1'], 'body', ['Content-Type' => 'text/plain']);

        $request = $http->requests[0];
        self::assertSame('PUT', $request->method->value);
        self::assertSame('http://127.0.0.1:9100/backups/a%20b/%C3%BC.txt?versionId=v1', $request->uri);
        self::assertSame('body', $request->body);
        self::assertSame('127.0.0.1:9100', $request->headers['Host']);
        self::assertSame(hash('sha256', 'body'), $request->headers['x-amz-content-sha256']);
        self::assertSame('20261018T120000Z', $request->headers['x-amz-date']);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20261018/us-east-1/s3/aws4_request, SignedHeaders=content-type;host;user-agent;x-amz-content-sha256;x-amz-date, Signature=', $request->headers['Authorization']);
    }

    public function testVirtualHostStyle(): void
    {
        $http = new MockHttpClient(new Response(200, ''));

        $this->connection($http, 'https://s3.eu-central-1.amazonaws.com', false)->send(Method::Get, 'backups', 'key.txt');

        self::assertSame('https://backups.s3.eu-central-1.amazonaws.com/key.txt', $http->requests[0]->uri);
        self::assertSame('backups.s3.eu-central-1.amazonaws.com', $http->requests[0]->headers['Host']);
    }

    public function testRetriesIdempotentMethodsOnly(): void
    {
        $http = new MockHttpClient(new Response(500), new TransportException('reset'), new Response(200), new Response(500));
        $connection = $this->connection($http);

        self::assertSame(200, $connection->send(Method::Put, 'b', 'k', body: 'x')->statusCode);
        self::assertSame(500, $connection->send(Method::Post, 'b', 'k', ['uploads' => ''])->statusCode);
        self::assertSame(4, $http->callCount());
    }

    public function testAPostIsNeverRetriedAfterATransportFailure(): void
    {
        $http = new MockHttpClient(new TransportException('reset'));

        $this->expectException(TransportException::class);

        $this->connection($http)->send(Method::Post, 'b', '', ['delete' => '']);
    }

    public function testThrottleIsNotRetriedByDefault(): void
    {
        $slowDown = new Response(503, '<Error><Code>SlowDown</Code><Message>Reduce your request rate.</Message></Error>');
        $http = new MockHttpClient($slowDown, new Response(429), $slowDown, new Response(200));

        self::assertSame(503, $this->connection($http)->send(Method::Get, 'b', 'k')->statusCode);
        self::assertSame(429, $this->connection($http)->send(Method::Get, 'b', 'k')->statusCode);
        self::assertSame(200, $this->connection($http, options: new ClientOptions(retryBaseDelay: 0.0, retryOnThrottle: true))->send(Method::Get, 'b', 'k')->statusCode);
    }

    public function testHeadIsSent(): void
    {
        $http = new MockHttpClient(new Response(200));

        $this->connection($http)->send(Method::Head, 'b');

        self::assertSame('HEAD', $http->requests[0]->method->value);
        self::assertSame('http://127.0.0.1:9100/b', $http->requests[0]->uri);
    }

    public function testErrorWith200Raises(): void
    {
        $response = new Response(200, '<?xml version="1.0"?><Error><Code>InternalError</Code><Message>We encountered an internal error.</Message><RequestId>656c76696e6727732072657175657374</RequestId></Error>');

        try {
            Xml::parse($response, 'CompleteMultipartUploadResult', $this->connection(new MockHttpClient()));
            self::fail('No exception.');
        } catch (ApiException $exception) {
            self::assertSame('InternalError', $exception->errorCode);
            self::assertSame('656c76696e6727732072657175657374', $exception->requestId);
            self::assertSame(200, $exception->statusCode);
        }
    }

    public function testErrorCarriesCodeMessageAndRequestId(): void
    {
        $http = new MockHttpClient(new Response(404, '<Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message><RequestId>R1</RequestId></Error>', [], 'Not Found'));
        $connection = $this->connection($http);
        $response = $connection->send(Method::Get, 'b', 'k?x');

        $exception = $connection->failure($response);

        self::assertInstanceOf(NotFoundException::class, $exception);
        self::assertSame('NoSuchKey', $exception->errorCode);
        self::assertSame('R1', $exception->requestId);
        self::assertStringContainsString('The specified key does not exist.', $exception->getMessage());
    }

    public function testHtmlIsASerializationError(): void
    {
        $this->expectException(SerializationException::class);

        Xml::parse(new Response(200, '<html><body>maintenance</body>'), 'ListBucketResult', $this->connection(new MockHttpClient()));
    }

    public function testUnexpectedRootIsNamed(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('expected ListBucketResult, found ListVersionsResult');

        Xml::parse(new Response(200, '<ListVersionsResult/>'), 'ListBucketResult', $this->connection(new MockHttpClient()));
    }

    public function testExternalEntitiesAreNotResolved(): void
    {
        $xml = Xml::parse(new Response(200, '<!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/hostname">]><ListBucketResult><Name>&x;</Name></ListBucketResult>'), 'ListBucketResult', $this->connection(new MockHttpClient()));

        self::assertSame('', trim((string) $xml->Name));
    }

    public function testSecretIsNotInDebugInfoOrExceptions(): void
    {
        $s3 = new S3('http://127.0.0.1:9100', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', self::SECRET, httpClient: new MockHttpClient(new TransportException('down'), new TransportException('down'), new TransportException('down')), options: new ClientOptions(retryBaseDelay: 0.0));

        self::assertStringNotContainsString('EXAMPLEKEY', print_r($s3, true));
        self::assertStringNotContainsString('EXAMPLEKEY', print_r($s3->bucket('b'), true));

        try {
            $s3->bucket('b')->exists();
            self::fail('No exception.');
        } catch (Throwable $exception) {
            self::assertStringNotContainsString('EXAMPLEKEY', $exception->getMessage());
            self::assertStringNotContainsString('EXAMPLEKEY', $exception->getTraceAsString());
        }
    }

    private function connection(MockHttpClient $http, string $endpoint = 'http://127.0.0.1:9100', bool $pathStyle = true, ?ClientOptions $options = null): Connection
    {
        $clock = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-18T12:00:00Z');
            }
        };

        return new Connection(
            new Addressing($endpoint, $pathStyle),
            new Signature('AKIAIOSFODNN7EXAMPLE', self::SECRET, 'us-east-1'),
            $options ?? new ClientOptions(retryBaseDelay: 0.0),
            $http,
            $clock,
        );
    }
}
