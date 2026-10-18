<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Exception\SerializationException;
use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\Response;
use SensitiveParameter;
use SimpleXMLElement;

/**
 * Reads S3's XML answers without network access or external entities.
 *
 * @internal
 */
final class Xml
{
    /**
     * The document of a successful answer with the expected root.
     *
     * @throws ApiException           for an error response, also an `<Error>` with HTTP 200
     *                                (e.g. CompleteMultipartUpload)
     * @throws SerializationException for a body that is not XML or has another root
     */
    public static function parse(Response $response, string $root, #[SensitiveParameter] Connection $connection): SimpleXMLElement
    {
        $xml = self::load($response->body);

        if (!$response->isSuccessful || $xml?->getName() === 'Error') {
            throw $connection->failure($response);
        }

        if ($xml === null) {
            throw new SerializationException("The S3 answer (HTTP {$response->statusCode}) is not XML.");
        }

        if ($xml->getName() !== $root) {
            throw new SerializationException("The S3 answer has an unexpected root: expected {$root}, found {$xml->getName()}.");
        }

        return $xml;
    }

    /**
     * The XML document, or null when the body is not XML.
     */
    public static function load(string $body): ?SimpleXMLElement
    {
        if (trim($body) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, options: \LIBXML_NONET | \LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    /**
     * The trimmed text of an element; null when it is missing or empty.
     */
    public static function text(?SimpleXMLElement $element): ?string
    {
        $text = $element === null ? '' : trim((string) $element);

        return $text === '' ? null : $text;
    }

    /**
     * A required text, else a SerializationException naming the field.
     */
    public static function required(?SimpleXMLElement $element, string $field): string
    {
        return self::text($element) ?? throw new SerializationException("The S3 answer lacks {$field}.");
    }

    /**
     * An ISO 8601 time as S3 writes it (`2026-10-18T10:00:00.000Z`), in UTC.
     */
    public static function date(?SimpleXMLElement $element, string $field): DateTimeImmutable
    {
        $text = self::required($element, $field);

        return self::parseDate($text) ?? throw new SerializationException("The S3 answer has an unreadable {$field}.");
    }

    /**
     * Like {@see date()}, but null when the time is missing or unreadable, so one
     * odd entry does not make a whole listing unreadable.
     */
    public static function nullableDate(?SimpleXMLElement $element): ?DateTimeImmutable
    {
        $text = self::text($element);

        return $text === null ? null : self::parseDate($text);
    }

    /**
     * An ISO 8601 time, only if it names a real moment (no 30 February, no hour 25).
     */
    private static function parseDate(string $text): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $text, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            return null;
        }

        try {
            return new DateTimeImmutable((string) preg_replace('/(\.\d{6})\d+/', '$1', $text))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Whether an element says `true`.
     */
    public static function bool(?SimpleXMLElement $element): bool
    {
        return strtolower(self::text($element) ?? '') === 'true';
    }

    /**
     * An ETag without the quotes S3 puts around it.
     */
    public static function etag(?string $etag): string
    {
        return trim((string) $etag, '" ');
    }

    /**
     * Escapes text for an XML request body.
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
