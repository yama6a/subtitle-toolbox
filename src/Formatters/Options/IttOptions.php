<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\FormatWriteOptions;

final class IttOptions implements FormatWriteOptions
{
    public const FRAME_RATES = [23.976, 24.0, 25.0, 29.97, 30.0];


    public function __construct(
        public readonly ?float $frameRate = null,            // one of FRAME_RATES, null takes the frame rate that IttParser stored
    ) {
        if ($frameRate !== null && self::supportedFrameRate($frameRate) === null) {
            throw new InvalidArgumentException("The ITT formatter accepts the frame rates 23.976, 24, 25, 29.97 and 30, got $frameRate.");
        }
    }


    /**
     * Returns the entry of FRAME_RATES within 0.01 of $fps, or null.
     */
    public static function supportedFrameRate(float $fps): ?float
    {
        foreach (self::FRAME_RATES as $supported) {
            if (abs($fps - $supported) < 0.01) {
                return $supported;
            }
        }

        return null;
    }
}
