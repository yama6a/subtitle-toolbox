<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

/**
 * @internal
 */
final class Console
{
    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdin,
        private $stdout,
        private $stderr,
    ) {
    }


    public function out(string $text): void
    {
        fwrite($this->stdout, $text);
    }


    public function err(string $text): void
    {
        fwrite($this->stderr, $text);
    }


    public function readStdin(): string
    {
        return (string)stream_get_contents($this->stdin);
    }
}
