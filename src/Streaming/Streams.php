<?php

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\StringHelpers;

/**
 * @internal
 */
final class Streams
{
    /**
     * Yields the lines of a stream resource or file path without the UTF-8 BOM and without line endings.
     *
     * @param resource|string $stream
     *
     * @return Generator<int, string>
     */
    public static function lines($stream): Generator
    {
        $handle = self::open($stream, "rb");
        try {
            $first = true;
            while (($chunk = fgets($handle)) !== false) {
                if ($first) {
                    $chunk = StringHelpers::removeUtf8Bom($chunk);
                    $first = false;
                }
                // fgets() ends at LF only, so a chunk can still hold the CR line endings of old Mac files.
                $lines = explode(StringHelpers::UNIX_LINE_ENDING, StringHelpers::normalizeEOLs($chunk));
                if (end($lines) === "") {
                    array_pop($lines);
                }
                foreach ($lines as $line) {
                    yield $line;
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
