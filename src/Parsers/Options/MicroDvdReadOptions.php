<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\FrameRate;

final class MicroDvdReadOptions implements FormatReadOptions
{
    /**
     * @param ?float $frameRate Frames per second. It wins over a {1}{1}<fps> first line. Null takes that line.
     */
    public function __construct(public readonly ?float $frameRate = null)
    {
        if ($frameRate !== null) {
            FrameRate::check($frameRate);
        }
    }
}
