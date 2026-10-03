<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\FormatWriteOptions;
use SubtitleToolbox\Parsers\EbuStlParser;

final class EbuStlOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly ?int $frameRate = null,              // 25 or 30, null takes the disk format code of the subtitle, else 25
    ) {
        if ($frameRate !== null && !in_array($frameRate, EbuStlParser::FRAME_RATES, true)) {
            throw new InvalidArgumentException("The EBU STL formatter writes 25 or 30 fps, got $frameRate.");
        }
    }
}
