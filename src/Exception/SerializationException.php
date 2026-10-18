<?php

declare(strict_types=1);

namespace GoSuccess\S3\Exception;

use RuntimeException;

/**
 * A request payload could not be encoded, or a response body could not be
 * decoded into the expected shape.
 */
final class SerializationException extends RuntimeException implements S3Exception {}
