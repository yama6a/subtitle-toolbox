<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * @internal
 */
final class RawJson
{
    /**
     * Encodes a JSON value for the raw lines of a ParseWarning. Invalid UTF-8 becomes U+FFFD.
     */
    public static function encode(mixed $value): string
    {
        // json_decode() reads a number such as 1e400 as INF, which JSON cannot hold. Partial output writes 0 for it.
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            ?: "null";
    }
}
