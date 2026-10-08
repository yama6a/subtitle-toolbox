<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * @internal
 */
final class OptionsCopy
{
    /**
     * Returns a new object of the class of $base with the values of $base, and the values of $overrides by field name.
     *
     * @template T of object
     *
     * @param T                    $base
     * @param array<string, mixed> $overrides
     *
     * @return T
     */
    public static function with(object $base, array $overrides): object
    {
        return new ($base::class)(...[...get_object_vars($base), ...$overrides]);
    }
}
