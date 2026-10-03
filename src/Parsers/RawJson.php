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
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
