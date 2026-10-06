<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\IttFrameRates;

final class IttWriteOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly ?float $frameRate = null,            // 23.976, 24, 25, 29.97 or 30, null takes the frame rate that IttParser stored
    ) {
        if ($frameRate !== null && IttFrameRates::supported($frameRate) === null) {
            throw new InvalidArgumentException("The ITT formatter accepts the frame rates 23.976, 24, 25, 29.97 and 30, got $frameRate.");
        }
    }
}
