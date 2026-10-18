<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

/**
 * The credentials are missing or the signature is invalid (HTTP 401).
 */
final class AuthenticationException extends ApiException {}
