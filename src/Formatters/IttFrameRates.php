<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

/**
 * The frame rates that IttFormatter writes and IttWriteOptions accepts.
 *
 * @internal
 */
final class IttFrameRates
{
    /** frames per second => [ttp:frameRate, ttp:frameRateMultiplier] */
    public const PARAMETERS = [
        "23.976" => ["24", "999 1000"],
        "24"     => ["24", "1 1"],
        "25"     => ["25", "1 1"],
        "29.97"  => ["30", "999 1000"],
        "30"     => ["30", "1 1"],
    ];


    /**
     * Returns the key of PARAMETERS within 0.01 of $frameRate, or null.
     */
    public static function supported(float $frameRate): ?string
    {
        foreach (array_keys(self::PARAMETERS) as $supported) {
            if (abs($frameRate - (float) $supported) < 0.01) {
                return (string) $supported;
            }
        }

        return null;
    }
}
