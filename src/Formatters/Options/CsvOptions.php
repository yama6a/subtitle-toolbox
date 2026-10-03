<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\CsvTimeFormat;
use SubtitleToolbox\Formatters\FormatWriteOptions;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Subtitle;

/**
 * A null field takes the value that CsvParser stored, else the default of the format.
 */
final class CsvOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly ?string $delimiter = null,           // ",", ";" or "\t", default ","
        public readonly ?CsvTimeFormat $timeFormat = null,   // default CsvTimeFormat::Dot
        public readonly ?float $frameRate = null,            // frames per second, needed for CsvTimeFormat::Frames
        public readonly ?Subtitle $secondText = null,        // writes the text of these cues into a column after the text
        public readonly string $secondTextHeader = "text2",  // header of the secondText column
        public readonly bool $escapeFormulas = false,        // puts ' before a cell that starts with =, +, - or @
    ) {
        if ($delimiter !== null) {
            CsvParser::checkDelimiter($delimiter);
        }
        if ($frameRate !== null && $frameRate <= 0) {
            throw new InvalidArgumentException("The CSV frame rate must be greater than 0, got $frameRate.");
        }
    }
}
