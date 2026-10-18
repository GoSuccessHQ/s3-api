<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

use DateTimeImmutable;
use DateTimeZone;
use GoSuccess\S3\Http\Response;

/**
 * The request was rejected because the rate limit was exceeded (HTTP 429).
 *
 * The server throttled the request (HTTP 429). The client retries it only with
 * `ClientOptions::$retryOnThrottle`; {@see $retryAfter} holds the number of
 * seconds to wait, when the server announced it.
 */
final class RateLimitException extends ApiException
{
    /**
     * The HTTP-date formats of RFC 9110, section 5.6.7: the IMF-fixdate and the
     * obsolete RFC 850 and asctime() formats, which recipients must accept too.
     */
    private const array HTTP_DATE_FORMATS = ['D, d M Y H:i:s \\G\\M\\T', 'l, d-M-y H:i:s \\G\\M\\T', 'D M j H:i:s Y'];

    /**
     * @param int|null $retryAfter Seconds until the API accepts requests again, from
     *                             `Retry-After`.
     */
    public function __construct(
        string $message,
        int $statusCode,
        string $responseBody = '',
        ?string $errorCode = null,
        ?string $requestId = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $responseBody, $errorCode, $requestId);
    }

    /**
     * Parse a `Retry-After` header value into seconds.
     *
     * Supports the two forms of RFC 9110: delay-seconds ("30") and an HTTP-date
     * ("Wed, 21 Oct 2026 07:28:00 GMT"); a date in the past means no wait.
     * Returns null for an empty value and for anything else, e.g. "1.5", "-1"
     * or "tomorrow", so that the caller falls back to other hints.
     *
     * @param float|null $now Current Unix time for the HTTP-date form; defaults to
     *                        the system time.
     */
    public static function parseRetryAfter(string $value, ?float $now = null): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Not ctype_digit(): ext-ctype is optional, and not a requirement of the package.
        if (preg_match('/^\d+$/D', $value) === 1) {
            // An absurdly long value saturates at PHP_INT_MAX; callers cap it anyway.
            return (int) $value;
        }

        $date = self::parseHttpDate($value);

        if ($date === null) {
            return null;
        }

        return max(0, (int) ceil($date->getTimestamp() - ($now ?? microtime(true))));
    }

    /**
     * How long the API asked to wait after a response, in seconds: the
     * `Retry-After` header if it is valid (see {@see parseRetryAfter()}).
     *
     * @internal
     *
     * @param float $now Clock time at which the response arrived.
     */
    public static function announcedDelay(Response $response, float $now): ?float
    {
        $retryAfter = self::parseRetryAfter($response->header('retry-after'), $now);

        return $retryAfter === null ? null : (float) $retryAfter;
    }

    /**
     * An HTTP-date, parsed strictly. strtotime() would also take relative
     * dates ("tomorrow"), times of day ("10.0") and bare zone offsets ("-1").
     */
    private static function parseHttpDate(string $value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        foreach (self::HTTP_DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat("!{$format}", $value, $utc);

            if ($date === false) {
                continue;
            }

            $canonical = $date->format($format);

            if ($format === 'D M j H:i:s Y') {
                // asctime() pads a single-digit day with a space: "Sun Nov  6".
                $canonical = (string) preg_replace('/^(\w{3} \w{3}) (\d) /', '$1  $2 ', $canonical);
            }

            // The parser tolerates a weekday that does not match the date (it
            // moves the date instead), overflowing fields and lower case; the
            // round trip does not.
            if ($canonical === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * {@see announcedDelay()} rounded up to whole seconds.
     *
     * @internal
     */
    public static function retryAfter(Response $response, float $now): ?int
    {
        $delay = self::announcedDelay($response, $now);

        // Never above PHP_INT_MAX, whose float value would not convert back.
        return $delay === null ? null : (int) min(ceil($delay), 9.0E18);
    }
}
