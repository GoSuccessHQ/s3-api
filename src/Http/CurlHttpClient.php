<?php

declare(strict_types=1);

namespace GoSuccess\S3\Http;

use CurlHandle;
use GoSuccess\S3\ClientOptions;
use GoSuccess\S3\Exception\TransportException;
use SensitiveParameter;

/**
 * Default {@see StreamingHttpClient} built on PHP's cURL extension.
 *
 * Has no Composer dependencies; only requires `ext-curl`. One instance reuses a
 * single cURL handle, so connections stay alive across requests. On PHP 8.5+ it
 * additionally shares DNS, connection and TLS session caches across requests of
 * the same worker process through a persistent share handle.
 */
final class CurlHttpClient implements StreamingHttpClient
{
    /**
     * Seconds a request without a total time limit may stall (below 1 byte/s)
     * before it is aborted.
     */
    private const int STALL_SECONDS = 60;

    /**
     * Upper bound for a timeout in milliseconds (about 24 days), so converting
     * a huge float can never overflow the integer range.
     */
    private const float MAX_MILLISECONDS = 2_147_483_647.0;

    private ?CurlHandle $handle = null;

    /**
     * Persistent share handle (PHP 8.5+ only, hence untyped).
     */
    private ?object $share = null;

    /**
     * @param float $timeout               Default maximum duration of a whole request in
     *                                     seconds; a request may override it, as the client
     *                                     does with {@see ClientOptions::$timeout}.
     * @param float $connectTimeout        Default maximum duration of the connection phase in
     *                                     seconds; a request may override it, as the client
     *                                     does with {@see ClientOptions::$connectTimeout}.
     * @param bool  $persistentConnections Share DNS, connection and TLS session caches
     *                                     across requests of the worker process (PHP 8.5+).
     */
    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        private readonly bool $persistentConnections = true,
    ) {}

    public function send(#[SensitiveParameter] Request $request): Response
    {
        return $this->perform($request, null);
    }

    public function sendToFile(#[SensitiveParameter] Request $request, string $path): Response
    {
        $file = @fopen($path, 'wb');

        if ($file === false) {
            throw new TransportException("Cannot open {$path} for writing the response body.");
        }

        try {
            return $this->perform($request, $file);
        } finally {
            fclose($file);
        }
    }

    /**
     * @param resource|null $file Where the body goes; null keeps it in the response.
     */
    private function perform(#[SensitiveParameter] Request $request, $file): Response
    {
        $handle = $this->handle ??= curl_init();
        curl_reset($handle);

        /** @var array<string, string> $headers */
        $headers = [];
        $reasonPhrase = '';
        $body = '';

        $options = [
            \CURLOPT_URL => $request->uri,
            \CURLOPT_CUSTOMREQUEST => $request->method->value,
            // A HEAD answer has no body; without NOBODY cURL would wait for one.
            \CURLOPT_NOBODY => $request->method === Method::Head,
            // A redirect could carry the Authorization header to another host.
            \CURLOPT_FOLLOWLOCATION => false,
            // Never file://, ftp:// or the like, whatever the URI says.
            \CURLOPT_PROTOCOLS_STR => 'http,https',
            // Keys are signed literally; without this cURL would drop "." and ".." segments.
            \CURLOPT_PATH_AS_IS => true,
            \CURLOPT_HTTPHEADER => $this->formatHeaders($request->headers),
            \CURLOPT_CONNECTTIMEOUT_MS => $this->milliseconds($request->connectTimeout ?? $this->connectTimeout),
            \CURLOPT_HEADERFUNCTION => static function (CurlHandle $_handle, string $line) use (&$headers, &$reasonPhrase): int {
                $trimmed = trim($line);

                if (str_starts_with($trimmed, 'HTTP/')) {
                    // A new response starts (e.g. after "100 Continue"): drop earlier headers.
                    $headers = [];
                    $reasonPhrase = explode(' ', $trimmed, 3)[2] ?? '';
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $headers[$name] = isset($headers[$name]) ? "{$headers[$name]}, {$value}" : $value;
                }

                return \strlen($line);
            },
            \CURLOPT_WRITEFUNCTION => static function (CurlHandle $_handle, string $chunk) use (&$body, $file): int {
                if ($file === null) {
                    $body .= $chunk;

                    return \strlen($chunk);
                }

                // A failed or short write (e.g. a full disk) aborts the transfer with
                // cURL error 23, a TransportException; silenced so that a strict error
                // handler does not turn the notice into another exception first.
                return (int) @fwrite($file, $chunk);
            },
        ];

        $timeout = $request->timeout ?? $this->timeout;
        $options[\CURLOPT_TIMEOUT_MS] = $this->milliseconds($timeout);

        if ($options[\CURLOPT_TIMEOUT_MS] === 0) {
            // No total limit, but never hang on a dead connection.
            $options[\CURLOPT_LOW_SPEED_LIMIT] = 1;
            $options[\CURLOPT_LOW_SPEED_TIME] = self::STALL_SECONDS;
        }

        $this->applyBody($request, $options);
        $this->applyShare($options);

        curl_setopt_array($handle, $options);

        if (curl_exec($handle) === false) {
            throw new TransportException(
                "cURL error while requesting {$this->redact($request->uri)}: " . curl_error($handle),
                curl_errno($handle),
            );
        }

        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);

        return new Response(
            statusCode: \is_int($status) ? $status : 0,
            body: $body,
            headers: $headers,
            reasonPhrase: $reasonPhrase,
        );
    }

    /**
     * @param array<int, mixed> $options
     */
    private function applyBody(Request $request, array &$options): void
    {
        if ($request->body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $request->body;

            return;
        }

        if (!$request->method->isIdempotent() || $request->method === Method::Put) {
            // Send an explicit "Content-Length: 0"; servers may reject a body-less
            // POST without it (411 Length Required), e.g. completing a multipart upload.
            $options[\CURLOPT_POSTFIELDS] = '';
        }
    }

    /**
     * @param array<int, mixed> $options
     */
    private function applyShare(array &$options): void
    {
        if (!$this->persistentConnections || !\function_exists('curl_share_init_persistent')) {
            return;
        }

        if ($this->share === null) {
            $share = curl_share_init_persistent([
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_CONNECT,
                \CURL_LOCK_DATA_SSL_SESSION,
            ]);

            if (!\is_object($share)) {
                return;
            }

            $this->share = $share;
        }

        $options[\CURLOPT_SHARE] = $this->share;
    }

    /**
     * Convert seconds to whole milliseconds for cURL.
     *
     * A non-positive value means "no limit" (cURL's 0). A small positive value
     * is rounded up to at least 1ms so it never collapses to "no limit".
     */
    private function milliseconds(float $seconds): int
    {
        if (is_nan($seconds) || $seconds <= 0.0) {
            return 0;
        }

        return max(1, (int) min(round($seconds * 1000), self::MAX_MILLISECONDS));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        $hasContentType = false;

        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
            $hasContentType = $hasContentType || strtolower($name) === 'content-type';
        }

        if (!$hasContentType) {
            // cURL would otherwise label every body as a form submission.
            $formatted[] = 'Content-Type:';
        }

        // Disable "Expect: 100-continue", which costs a round trip on uploads.
        $formatted[] = 'Expect:';

        return $formatted;
    }

    /**
     * Drop the query string, which may carry filters or cursors, from error messages.
     */
    private function redact(string $uri): string
    {
        return explode('?', $uri, 2)[0];
    }
}
