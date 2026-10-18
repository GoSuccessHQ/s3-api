<?php

declare(strict_types=1);

namespace GoSuccess\S3\Http;

use GoSuccess\S3\Exception\TransportException;
use SensitiveParameter;

/**
 * A transport that can write the response body into a file instead of memory.
 *
 * The feed is one large document; holding it in a string would cost its whole
 * size in memory, and decoding it at once several times that. The client
 * downloads it into a temporary file and reads it from there in pieces.
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
