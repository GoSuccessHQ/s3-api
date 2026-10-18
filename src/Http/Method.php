<?php

declare(strict_types=1);

namespace GoSuccess\S3\Http;

/**
 * HTTP methods. The feed is only read with GET; the others exist for custom
 * transports and tests that share the Http layer of the GoSuccess clients.
 */
enum Method: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';

    /**
     * Whether repeating the request has the same effect as sending it once;
     * only such requests are retried after a server error or a lost connection.
     */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Put, self::Delete => true,
            self::Post, self::Patch => false,
        };
    }
}
