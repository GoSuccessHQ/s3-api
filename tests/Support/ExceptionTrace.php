<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Support;

use LogicException;
use Throwable;

/**
 * Inspects the arguments that exception traces record, to check that secrets
 * stay out of them.
 */
final class ExceptionTrace
{
    /**
     * Run an action with arguments recorded in exception traces, as PHP does by
     * default without a php.ini (php.ini-production turns it off), and return
     * the exception it throws.
     *
     * @param callable(): mixed $action
     */
    public static function capture(callable $action): Throwable
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $action();
        } catch (Throwable $e) {
            return $e;
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }

        throw new LogicException('The action did not throw.');
    }

    /**
     * The arguments of every frame as JSON, like a logger that serializes the
     * trace would write them.
     */
    public static function arguments(Throwable $e): string
    {
        return (string) json_encode(array_map(static fn(array $frame): mixed => $frame['args'] ?? null, $e->getTrace()), \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
