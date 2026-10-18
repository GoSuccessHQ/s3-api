<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

/**
 * The request was malformed or contained invalid parameters (HTTP 400).
 */
final class BadRequestException extends ApiException {}
