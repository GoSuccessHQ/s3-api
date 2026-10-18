<?php

declare(strict_types=1);

namespace GoSuccess\S3;

use GoSuccess\S3\Exception\ApiException;
use GoSuccess\S3\Exception\SerializationException;
use GoSuccess\S3\Http\Connection;
use GoSuccess\S3\Http\Response;
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
    public static function parse(Response $response, string $root, Connection $connection): SimpleXMLElement
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
     * Escapes text for an XML request body.
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
