<?php

declare(strict_types=1);

namespace GoSuccess\S3\Http;

use GoSuccess\S3\Addressing;
use GoSuccess\S3\ClientOptions;
use GoSuccess\S3\Clock;
use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Exception\ErrorDetails;
use GoSuccess\S3\Exception\RateLimitException;
use GoSuccess\S3\Exception\TransportException;
use GoSuccess\S3\Signature;
use GoSuccess\S3\Xml;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Signed requests to one S3 endpoint with retries.
 *
 * Network failures and server errors are retried for idempotent methods only
 * (GET, HEAD, PUT, DELETE), never for POST (multipart, multi-delete). A throttle
 * (`429`, `503 SlowDown`) is retried only with `retryOnThrottle`. The last
 * response is returned whatever its status; the bucket decides what it means.
 *
 * @internal
 */
final class Connection
{
    /** The request behind the last response, for {@see failure()}. */
    private ?Request $lastRequest = null;

    public function __construct(
        private readonly Addressing $addressing,
        #[SensitiveParameter]
        private readonly Signature $signature,
        private readonly ClientOptions $options,
        private readonly HttpClient $httpClient,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function send(Method $method, string $bucket, string $key = '', array $query = [], string $body = '', array $headers = []): Response
    {
        return $this->retrying($method, fn(): Response => $this->httpClient->send($this->request($method, $bucket, $key, $query, $body, $headers)));
    }

    /**
     * Like {@see send()}, with the body written to the file at `$path`.
     *
     * @param array<string, string> $query
     */
    public function sendToFile(string $bucket, string $key, array $query, string $path): Response
    {
        if (!$this->httpClient instanceof StreamingHttpClient) {
            throw new LogicException('Downloading to a file needs a StreamingHttpClient.');
        }

        $client = $this->httpClient;

        return $this->retrying(Method::Get, fn(): Response => $client->sendToFile($this->request(Method::Get, $bucket, $key, $query, '', []), $path));
    }

    /**
     * A presigned URL for a GET of the key, valid for `$seconds`.
     *
     * @param array<string, string> $query
     */
    public function presignedUrl(string $bucket, string $key, int $seconds, array $query): string
    {
        $signed = $this->signature->presignedQuery('GET', $this->addressing->path($bucket, $key), $this->addressing->host($bucket), $seconds, $query, $this->clock->now());

        return $this->addressing->url($bucket, $key) . '?' . Signature::encodeQuery($signed);
    }

    /**
     * The exception for an error response of the last request.
     */
    public function failure(Response $response): ApiException
    {
        if ($this->lastRequest !== null) {
            return ApiException::fromResponse($response, $this->lastRequest);
        }

        $details = ErrorDetails::parse($response->body);
        $message = "The S3 request failed with HTTP {$response->statusCode}"
            . ($details->message === null ? '' : ": {$details->message}")
            . ($details->errorCode === null ? '' : " ({$details->errorCode})");

        return new ApiException($message, $response->statusCode, $response->body, $details->errorCode, $details->requestId);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function request(Method $method, string $bucket, string $key, array $query, string $body, array $headers): Request
    {
        foreach ($headers as $name => $value) {
            // A line break would end the header and inject another, unsigned one.
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('A header name must be a token and a header value must not contain control characters.');
            }
        }

        $signed = $this->signature->headersFor(
            $method->value,
            $this->addressing->path($bucket, $key),
            $query,
            [...$headers, 'Host' => $this->addressing->host($bucket), 'User-Agent' => $this->options->userAgent],
            Signature::hashPayload($body),
            $this->clock->now(),
        );
        $encodedQuery = Signature::encodeQuery($query);
        $url = $this->addressing->url($bucket, $key);

        return $this->lastRequest = new Request(
            method: $method,
            uri: $encodedQuery === '' ? $url : "{$url}?{$encodedQuery}",
            headers: $signed,
            body: $body === '' ? null : $body,
            timeout: $this->options->timeout,
            connectTimeout: $this->options->connectTimeout,
        );
    }

    /**
     * @param callable(): Response $attempt Builds a freshly signed request each time; it holds the connection.
     */
    private function retrying(Method $method, #[SensitiveParameter] callable $attempt): Response
    {
        $retries = $method->isIdempotent() ? $this->options->maxRetries : 0;

        for ($try = 0; ; $try++) {
            try {
                $response = $attempt();
            } catch (TransportException $exception) {
                if ($try >= $retries) {
                    throw $exception;
                }

                $this->wait($try);

                continue;
            }

            if ($try >= $retries || !$this->retryable($response)) {
                return $response;
            }

            $this->wait($try, RateLimitException::parseRetryAfter($response->header('retry-after')));
        }
    }

    private function retryable(Response $response): bool
    {
        $throttled = $response->statusCode === 429
            || ($response->statusCode === 503 && Xml::load($response->body)?->Code->__toString() === 'SlowDown');

        return $throttled ? $this->options->retryOnThrottle : $response->statusCode >= 500;
    }

    /**
     * Waits with exponential backoff, or as long as the server asked (`Retry-After`), at most `maxRetryDelay`.
     */
    private function wait(int $try, ?int $retryAfter = null): void
    {
        $delay = min($this->options->maxRetryDelay, max((float) ($retryAfter ?? 0), $this->options->retryBaseDelay * (2 ** $try)));

        if ($delay > 0.0) {
            usleep((int) ($delay * 1_000_000));
        }
    }
}
