<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;

final class MpSubWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?float $frameRate Write frames at this rate. Null writes seconds.
     */
    public function __construct(
        public readonly ?float $frameRate = null,
    ) {
        // MPlayer and FFmpeg read FORMAT=<fps> as an integer.
        if ($frameRate !== null) {
            $message = "The MPSub frame rate must be a positive integer, got %s.";
            FrameRate::check($frameRate, $message);
            if ($frameRate !== floor($frameRate)) {
                throw new InvalidArgumentException(str_replace("%s", (string) $frameRate, $message));
            }
        }
    }
}
