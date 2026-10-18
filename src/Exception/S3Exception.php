<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception thrown by this library, so
 * callers can catch all of them with a single `catch (S3Exception $e)`.
 */
interface S3Exception extends Throwable {}
