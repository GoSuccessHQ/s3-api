<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

/**
 * Normalizes S3 error bodies: `<Error><Code>NoSuchKey</Code><Message>…</Message>
 * <RequestId>…</RequestId></Error>`; the code is the error code, the message the
 * message. Plain text or HTML (e.g. from a proxy) is shortened. XML is read
 * without network access or external entities.
 *
 * @internal
 */
final readonly class ErrorDetails
{
    private const int MAX_MESSAGE_BYTES = 500;

    private function __construct(
        public ?string $message,
        public ?string $errorCode,
        public ?string $requestId = null,
    ) {}

    public static function parse(string $body): self
    {
        if (trim($body) === '') {
            return new self(null, null);
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, options: \LIBXML_NONET | \LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false || $xml->getName() !== 'Error') {
            // Plain text, HTML or another XML document.
            return new self(self::excerpt($body), null);
        }

        $message = self::text((string) $xml->Message);

        return new self(
            $message === null ? null : self::excerpt($message),
            self::text((string) $xml->Code),
            self::text((string) $xml->RequestId),
        );
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function excerpt(string $text): string
    {
        $text = trim($text);

        if (\strlen($text) <= self::MAX_MESSAGE_BYTES) {
            return $text;
        }

        $cut = substr($text, 0, self::MAX_MESSAGE_BYTES);

        // Drop a trailing, incomplete multi-byte sequence (ext-mbstring is not required).
        return (preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut) ?? $cut) . '…';
    }
}
