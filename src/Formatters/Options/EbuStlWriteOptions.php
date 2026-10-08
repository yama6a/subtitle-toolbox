<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\Parsers\EbuStl;

final class EbuStlWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?float $frameRate 25 or 30. Null takes the disk format code of the subtitle, else 25.
     */
    public function __construct(
        public readonly ?float $frameRate = null,
    ) {
        if ($frameRate !== null && !in_array($frameRate, array_map(floatval(...), EbuStl::FRAME_RATES), true)) {
            throw new InvalidArgumentException("The EBU STL formatter writes " . implode(" or ", EbuStl::FRAME_RATES) .
                                               " fps, got " . OptionChecks::text($frameRate) . ".");
        }
    }
}
