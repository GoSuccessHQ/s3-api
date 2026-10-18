<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Support;

use Closure;
use GoSuccess\S3\Exception\TransportException;
use GoSuccess\S3\Http\Request;
use GoSuccess\S3\Http\Response;
use GoSuccess\S3\Http\StreamingHttpClient;
use RuntimeException;
use SensitiveParameter;

/**
 * Test double that returns a queue of predefined responses (or throws predefined
 * exceptions) and records every request it received.
 */
final class MockHttpClient implements StreamingHttpClient
{
    /** @var list<Response|TransportException> */
    private array $queue;

    /** @var list<Request> */
    public array $requests = [];

    /** @var list<string> Files that {@see sendToFile()} wrote to. */
    public array $files = [];

    /**
     * Called for every request before the queued outcome is returned, e.g. to
     * observe the time at which it was sent.
     *
     * @var (Closure(Request): void)|null
     */
    public ?Closure $onSend = null;

    public function __construct(Response|TransportException ...$queue)
    {
        $this->queue = array_values($queue);
    }

    public function send(#[SensitiveParameter] Request $request): Response
    {
        $this->requests[] = $request;

        if ($this->onSend !== null) {
            ($this->onSend)($request);
        }

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('MockHttpClient ran out of queued responses.');
        }

        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /**
     * Like {@see send()}, but writes the queued body to the file and returns the
     * response without a body, as the streaming contract demands.
     */
    public function sendToFile(#[SensitiveParameter] Request $request, string $path): Response
    {
        $this->files[] = $path;
        $response = $this->send($request);
        file_put_contents($path, $response->body);

        return new Response($response->statusCode, '', $response->headers, $response->reasonPhrase);
    }

    public function callCount(): int
    {
        return \count($this->requests);
    }

    /**
     * Decode the JSON body of the request at the given index.
     *
     * @return array<array-key, mixed>
     */
    public function jsonBody(int $index = 0): array
    {
        $body = ($this->requests[$index] ?? null)?->body;
        $decoded = \is_string($body) ? json_decode($body, true) : null;

        if (!\is_array($decoded)) {
            throw new RuntimeException("Request {$index} has no JSON object body.");
        }

        return $decoded;
    }
}
