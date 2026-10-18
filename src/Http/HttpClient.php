<?php

declare(strict_types=1);

namespace GoSuccess\S3\Http;

use GoSuccess\S3\Exception\TransportException;
use SensitiveParameter;

/**
 * Minimal HTTP transport abstraction.
 *
 * The library ships with {@see CurlHttpClient} (no Composer dependencies). Provide
 * your own implementation to route requests through an existing HTTP stack, e.g.
 * Guzzle, without the library depending on it.
 */
interface HttpClient
{
    /**
     * Send a request and return the response.
     *
     * Implementations MUST:
     * - throw a {@see TransportException} for transport-level failures (connection
     *   errors, timeouts, …), but never for HTTP error status codes, which are
     *   returned as a normal {@see Response};
     * - return the response headers with lower-cased names;
     * - not follow redirects, so the signed request is never sent to another host.
     *
     * The request carries the signature in its `Authorization` header. Mark the
     * parameter `#[SensitiveParameter]` in an implementation as well (PHP does
     * not inherit the attribute), so that a stack trace with arguments never
     * holds the key.
     *
     * @throws TransportException
     */
    public function send(#[SensitiveParameter] Request $request): Response;
}
