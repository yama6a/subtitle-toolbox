<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\StringHelpers;

/**
 * @internal
 */
final class Streams
{
    /**
     * Yields the lines of a stream resource or file path without UTF-8 BOMs at their start and without line endings.
     *
     * @param resource|string $stream
     *
     * @return Generator<int, string>
     */
    public static function lines($stream): Generator
    {
        $handle = self::open($stream, "rb");
        try {
            while (($chunk = fgets($handle)) !== false) {
                // fgets() ends at LF only, so a chunk can still hold the CR line endings of old Mac files.
                $lines = explode(LineEnding::Lf->value, StringHelpers::normalizeEOLs($chunk));
                if (end($lines) === "") {
                    array_pop($lines);
                }
                // Joined files keep the BOM of each part at the start of a line.
                foreach ($lines as $line) {
                    yield StringHelpers::removeUtf8Bom($line);
                }
            }
        } finally {
            if (!is_resource($stream)) {
                fclose($handle);
            }
        }
    }


    /**
     * @param resource|string $stream
     *
     * @return resource
     */
    public static function open($stream, string $mode)
    {
        if (is_resource($stream) && get_resource_type($stream) === "stream") {
            return $stream;
        }
        if (!is_string($stream)) {
            throw new InvalidArgumentException("The stream must be a stream resource or a file path.");
        }

        $handle = @fopen($stream, $mode);
        if ($handle === false) {
            throw new InvalidArgumentException("Cannot open the file $stream.");
        }

        return $handle;
    }


    /**
     * @param resource|null $handle null after the writer closed the stream
     */
    public static function write($handle, string $text): void
    {
        if ($handle === null) {
            throw new InvalidArgumentException("The writer is closed.");
        }
        // fwrite() raises a notice before it returns false, and the exception reports the failure.
        if ($text !== "" && @fwrite($handle, $text) === false) {
            throw new InvalidArgumentException("Cannot write to the stream.");
        }
    }
}
