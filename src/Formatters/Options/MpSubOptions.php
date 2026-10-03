<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\FormatWriteOptions;

final class MpSubOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly ?int $frameRate = null,              // writes frames at this rate, null writes seconds
    ) {
        // MPlayer and FFmpeg read FORMAT=<fps> as an integer.
        if ($frameRate !== null && $frameRate <= 0) {
            throw new InvalidArgumentException("The MPSub frame rate must be a positive integer, got $frameRate.");
        }
    }
}
