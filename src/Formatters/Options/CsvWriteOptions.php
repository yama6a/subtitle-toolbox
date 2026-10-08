<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Subtitle;

/**
 * A null field takes the value that CsvParser stored, else the default of the format.
 */
final class CsvWriteOptions implements FormatWriteOptions
{
    /**
     * @param ?string        $delimiter        ",", ";" or a tab. The default is ",".
     * @param ?CsvTimeFormat $timeFormat       The time column format. The default is CsvTimeFormat::Dot.
     * @param ?float         $frameRate        Frames per second. CsvTimeFormat::Frames needs it.
     * @param ?Subtitle      $secondText       The cues whose text goes into a column after the text.
     * @param string         $secondTextHeader The header of the $secondText column.
     * @param bool           $escapeFormulas   Put ' before a cell that starts with =, +, - or @.
     */
    public function __construct(
        public readonly ?string $delimiter = null,
        public readonly ?CsvTimeFormat $timeFormat = null,
        public readonly ?float $frameRate = null,
        public readonly ?Subtitle $secondText = null,
        public readonly string $secondTextHeader = "text2",
        public readonly bool $escapeFormulas = false,
    ) {
        if ($delimiter !== null) {
            CsvReadOptions::checkDelimiter($delimiter);
        }
        if ($frameRate !== null) {
            FrameRate::check($frameRate, "The CSV frame rate must be greater than 0, got %s.");
        }
    }
}
