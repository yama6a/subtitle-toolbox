<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

/**
 * Runs "php -S" with a router script on a free local port until stop().
 */
final class LocalServer
{
    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        public readonly string $url,
    ) {
    }


    /**
     * @param array<string, string> $environment extra environment variables of the server
     */
    public static function start(string $router, array $environment = []): self
    {
        $socket = stream_socket_server("tcp://127.0.0.1:0");
        $port   = (int)substr(strrchr(stream_socket_get_name($socket, false), ":"), 1);
        fclose($socket);

        $null    = PHP_OS_FAMILY === "Windows" ? "NUL" : "/dev/null";
        $process = proc_open(
            [PHP_BINARY, "-S", "127.0.0.1:$port", $router],
            [0 => ["file", $null, "r"], 1 => ["file", $null, "w"], 2 => ["file", $null, "w"]],
            $pipes,
            null,
            $environment + getenv()
        );
        $server = new self($process, "http://127.0.0.1:$port");

        $deadline = microtime(true) + 10;
        while (($connection = @fsockopen("127.0.0.1", $port, $errorCode, $errorMessage, 0.1)) === false) {
            if (microtime(true) > $deadline) {
                $server->stop();
                throw new \RuntimeException("php -S did not start on port $port.");
            }
            usleep(20000);
        }
        fclose($connection);

        return $server;
    }


    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
