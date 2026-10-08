<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * Checks for an optional PHP extension or package, for example "gzuncompress" of ext-zlib or GlyphOcr\Recognizer.
 *
 * @internal
 */
final class Dependency
{
    /**
     * Returns true when $name is a defined function or a class that the autoloader finds.
     */
    public static function isAvailable(string $name): bool
    {
        return function_exists($name) || class_exists($name);
    }


    /**
     * @throws InvalidArgumentException with $message when isAvailable() is false for $name.
     */
    public static function check(string $name, string $message): void
    {
        if (!self::isAvailable($name)) {
            throw new InvalidArgumentException($message);
        }
    }
}
