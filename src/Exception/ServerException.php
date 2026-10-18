<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

/**
 * The S3 server failed to process the request (HTTP 5xx).
 */
final class ServerException extends ApiException {}
