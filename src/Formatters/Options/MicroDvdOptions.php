<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\FormatWriteOptions;

final class MicroDvdOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly float $frameRate,                    // frames per second of the video
        public readonly bool $writeFrameRateLine = false,    // writes the frame rate as the first line, {1}{1}23.976
    ) {
        if ($frameRate <= 0) {
            throw new InvalidArgumentException("The MicroDVD frame rate must be greater than 0, got $frameRate.");
        }
    }
}
