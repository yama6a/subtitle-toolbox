<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class MpSubWriteOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly ?float $frameRate = null,            // writes frames at this rate, null writes seconds
    ) {
        // MPlayer and FFmpeg read FORMAT=<fps> as an integer.
        if ($frameRate !== null && ($frameRate <= 0 || $frameRate !== floor($frameRate))) {
            throw new InvalidArgumentException("The MPSub frame rate must be a positive integer, got $frameRate.");
        }
    }
}
