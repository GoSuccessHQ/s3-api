<?php

declare(strict_types=1);

namespace GoSuccess\S3\Http;

use GoSuccess\S3\Exception\TransportException;
use SensitiveParameter;

/**
 * A transport that can write the response body into a file instead of memory.
 *
 * {@see \GoSuccess\S3\Bucket::getObjectToFile()} uses it, so a large object
 * never sits in memory as a whole.
 */
interface StreamingHttpClient extends HttpClient
{
    /**
     * Send a request and write the response body, also of an error response,
     * into the file at `$path` (created or truncated). The returned response
     * carries status, headers and reason phrase, and an empty body.
     *
     * The contract of {@see HttpClient::send()} applies otherwise.
     *
     * @throws TransportException
     */
    public function sendToFile(#[SensitiveParameter] Request $request, string $path): Response;
}
