<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Support;

use RuntimeException;

/**
 * Starts PHP's built-in web server with tests/Support/server.php as router.
 */
final class LocalServer
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var non-empty-string */
    public readonly string $baseUri;

    public function __construct()
    {
        $port = self::freePort();
        $this->baseUri = "http://127.0.0.1:{$port}";

        $process = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            throw new RuntimeException('Unable to start the local test server.');
        }

        $this->process = $process;
        $this->waitUntilReady($port);
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * A port that nothing listens on, for connection-refused tests.
     */
    public static function closedPort(): int
    {
        return self::freePort();
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Unable to allocate a local port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitUntilReady(int $port): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The local test server did not start in time.');
    }
}
