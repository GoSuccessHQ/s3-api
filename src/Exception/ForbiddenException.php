<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

/**
 * The access key is valid but not allowed to perform the request, e.g. a read-only key
 * or a feature the plan does not include (HTTP 403).
 */
final class ForbiddenException extends ApiException {}
