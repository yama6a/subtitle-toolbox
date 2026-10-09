<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
final class OptionsCopy
{
    /**
     * Returns a new object of the class of $base with the constructor values of $base, and the values of $overrides by field name.
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
        $parameters = array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass($base))->getConstructor()?->getParameters() ?? [],
        );
        $values = array_intersect_key(get_object_vars($base), array_flip($parameters));

        return new ($base::class)(...[...$values, ...$overrides]);
    }
}
