<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

/**
 * The API key is missing or invalid (HTTP 401).
 */
final class AuthenticationException extends ApiException {}
