<?php

namespace SubtitleToolbox;

/**
 * @internal
 */
final class Options
{
    /**
     * Returns the value of a true-or-false option, given as a key such as [$name => true] or as a list value such as [$name].
     * Returns null when $options holds neither.
     */
    public static function flag(array $options, string $name): mixed
    {
        if (array_key_exists($name, $options)) {
            return $options[$name];
        }

        return in_array($name, array_filter($options, "is_int", ARRAY_FILTER_USE_KEY), true) ? true : null;
    }
}
