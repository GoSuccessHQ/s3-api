<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Unit\Http;

use ErrorException;
use GoSuccess\S3\Exception\TransportException;
use GoSuccess\S3\Http\CurlHttpClient;
use GoSuccess\S3\Http\Method;
use GoSuccess\S3\Http\Request;
use GoSuccess\S3\Http\Response;
use GoSuccess\S3\Tests\Support\ExceptionTrace;
use GoSuccess\S3\Tests\Support\LocalServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;

/**
 * Exercises the real cURL transport against a local PHP web server.
 */
#[CoversClass(CurlHttpClient::class)]
final class CurlHttpClientTest extends TestCase
{
    private static ?LocalServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new LocalServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    public function testGetReturnsStatusBodyAndLowerCasedHeaders(): void
    {
        $response = $this->send(new Request(Method::Get, $this->uri('/echo?a=1'), ['X-Test' => 'yes']));

        self::assertSame(200, $response->statusCode);
        self::assertTrue($response->isSuccessful);
        self::assertSame('one, two', $response->header('X-CUSTOM'));
        self::assertSame('OK', $response->reasonPhrase);

        $echo = $this->decode($response);
        self::assertSame('GET', $echo['method']);
        self::assertSame('a=1', $echo['query']);
        self::assertIsArray($echo['headers']);
        self::assertSame('yes', $echo['headers']['x-test']);
    }

    public function testBodylessPostSendsZeroContentLengthWithoutFormContentType(): void
    {
        $echo = $this->decode($this->send(new Request(Method::Post, $this->uri('/echo'))));

        self::assertIsArray($echo['headers']);
        self::assertSame('0', $echo['headers']['content-length']);
        self::assertArrayNotHasKey('content-type', $echo['headers']);
    }

    public function testStringBodyIsSentWithTheGivenContentType(): void
    {
        $echo = $this->decode($this->send(new Request(
            Method::Patch,
            $this->uri('/echo'),
            ['Content-Type' => 'application/json'],
            '{"friendlyName":"Site"}',
        )));

        self::assertSame('PATCH', $echo['method']);
        self::assertSame('{"friendlyName":"Site"}', $echo['body']);
        self::assertIsArray($echo['headers']);
        self::assertSame('application/json', $echo['headers']['content-type']);
    }

    public function testHeadReturnsHeadersWithoutWaitingForABody(): void
    {
        $response = $this->send(new Request(Method::Head, $this->uri('/echo'), [], null, 5.0));

        self::assertSame(200, $response->statusCode);
        self::assertSame('', $response->body);
        self::assertSame('application/json', $response->header('content-type'));
    }

    public function testDotSegmentsAreSentAsIs(): void
    {
        $echo = $this->decode($this->send(new Request(Method::Get, $this->uri('/echo/./a/../b'))));

        self::assertSame('/echo/./a/../b', $echo['path']);
    }

    public function testCompressedBodiesStayCompressed(): void
    {
        $response = $this->send(new Request(Method::Get, $this->uri('/gzip')));

        self::assertSame(gzencode('hello'), $response->body);
    }

    public function testErrorResponsesAreReturned(): void
    {
        $response = $this->send(new Request(Method::Get, $this->uri('/status/404')));

        self::assertSame(404, $response->statusCode);
        self::assertFalse($response->isSuccessful);
        self::assertStringContainsString('<Code>Status404</Code>', $response->body);
    }

    public function testSendToFileWritesTheBodyIntoTheFile(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 's3-api-test-');

        try {
            $client = new CurlHttpClient();
            $response = $client->sendToFile(new Request(Method::Get, $this->uri('/echo'), ['X-Probe' => 'yes']), $path);
            $echo = json_decode((string) file_get_contents($path), true);

            self::assertSame(200, $response->statusCode);
            self::assertSame('', $response->body);
            self::assertSame('application/json', $response->header('content-type'));
            self::assertIsArray($echo);
            self::assertIsArray($echo['headers']);
            self::assertSame('yes', $echo['headers']['x-probe']);

            // The handle is reused: a later request returns its body in memory again.
            self::assertStringContainsString('"method":"GET"', $client->send(new Request(Method::Get, $this->uri('/echo')))->body);
        } finally {
            unlink($path);
        }
    }

    public function testSendToFileWritesErrorBodiesToo(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 's3-api-test-');

        try {
            $response = new CurlHttpClient()->sendToFile(new Request(Method::Get, $this->uri('/status/404')), $path);

            self::assertSame(404, $response->statusCode);
            self::assertStringContainsString('Status 404', (string) file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testAFailedWriteIsATransportErrorEvenUnderAStrictErrorHandler(): void
    {
        // Like Laravel's HandleExceptions: every reported error becomes an exception;
        // an error silenced with @ is not reported.
        set_error_handler(static function (int $severity, string $message): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity);
        });

        try {
            $this->expectException(TransportException::class);

            new CurlHttpClient()->sendToFile(new Request(Method::Get, $this->uri('/echo')), '/dev/full');
        } finally {
            restore_error_handler();
        }
    }

    public function testOnlyHttpAndHttpsAreFetched(): void
    {
        $this->expectException(TransportException::class);

        new CurlHttpClient()->send(new Request(Method::Get, 'file:///etc/hostname'));
    }

    public function testReusesTheHandleAcrossRequests(): void
    {
        $client = new CurlHttpClient();

        $first = $client->send(new Request(Method::Post, $this->uri('/echo'), ['Content-Type' => 'text/plain'], 'first'));
        $second = $client->send(new Request(Method::Get, $this->uri('/echo')));

        self::assertSame('first', $this->decode($first)['body']);
        // No option of the first request (method, body, headers) leaks into the second.
        $echo = $this->decode($second);
        self::assertSame('GET', $echo['method']);
        self::assertSame('', $echo['body']);
        self::assertIsArray($echo['headers']);
        self::assertArrayNotHasKey('content-type', $echo['headers']);
    }

    public function testConnectionFailureThrowsTransportException(): void
    {
        $port = LocalServer::closedPort();

        $this->expectException(TransportException::class);

        new CurlHttpClient(connectTimeout: 1.0)->send(new Request(Method::Get, "http://127.0.0.1:{$port}/"));
    }

    public function testRequestTimeoutOverridesTheDefault(): void
    {
        $this->expectException(TransportException::class);

        $this->send(new Request(Method::Get, $this->uri('/sleep/2000'), timeout: 0.3));
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testRequestConnectTimeoutOverridesTheDefault(): void
    {
        // A listener whose accept queue is full: Linux drops further SYNs, so a
        // connection hangs in the handshake until the connect timeout.
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, stream_context_create(['socket' => ['backlog' => 0]]));
        self::assertNotFalse($listener, 'Unable to listen on a local port.');
        $address = (string) stream_socket_get_name($listener, false);
        // A backlog of 0 still queues a connection; these fill the queue and must stay open.
        $queued = [];

        for ($i = 0; $i < 2; ++$i) {
            $queued[] = @stream_socket_client("tcp://{$address}", $errno, $error, 0.2, \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT);
        }

        usleep(100_000);
        $start = microtime(true);

        try {
            // Without the request's connect timeout, the connection would wait for the whole 5 seconds.
            new CurlHttpClient(connectTimeout: 30.0)->send(new Request(Method::Get, "http://{$address}/", timeout: 5.0, connectTimeout: 0.2));
            self::fail('Expected a TransportException.');
        } catch (TransportException $e) {
            // The wording differs between libcurl versions ("Connection timed out
            // after ..." vs. "Failed to connect ...: Timeout was reached"), the
            // error code does not.
            self::assertSame(\CURLE_OPERATION_TIMEDOUT, $e->getCode());
        }

        self::assertLessThan(2.0, microtime(true) - $start);
        self::assertCount(2, $queued);
    }

    public function testTransportErrorMessageOmitsTheQueryString(): void
    {
        $port = LocalServer::closedPort();

        try {
            new CurlHttpClient(connectTimeout: 1.0)->send(new Request(Method::Get, "http://127.0.0.1:{$port}/monitors?name=secret"));
            self::fail('Expected a TransportException.');
        } catch (TransportException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testTransportErrorTraceOmitsTheApiKey(): void
    {
        $port = LocalServer::closedPort();
        $request = new Request(Method::Get, "http://127.0.0.1:{$port}/", ['Authorization' => 'Bearer secret-key']);

        $e = ExceptionTrace::capture(static fn(): Response => new CurlHttpClient(connectTimeout: 1.0)->send($request));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertInstanceOf(SensitiveParameterValue::class, $e->getTrace()[0]['args'][0] ?? null);
        self::assertStringNotContainsString('secret-key', ExceptionTrace::arguments($e));
    }

    /**
     * @return non-empty-string
     */
    private function uri(string $path): string
    {
        self::assertNotNull(self::$server);

        return self::$server->baseUri . $path;
    }

    private function send(Request $request): Response
    {
        return new CurlHttpClient()->send($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
