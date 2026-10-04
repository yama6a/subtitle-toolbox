<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\FrameRate;

/**
 * The read settings of CSV and TSV tables.
 */
final class CsvReadOptions implements FormatReadOptions
{
    /**
     * @param ?CsvColumns $columns   The column layout. Null reads the columns by their header names.
     * @param ?string     $delimiter ",", ";" or a tab. Null detects it from the first line.
     * @param ?float      $frameRate Frames per second of times in hh:mm:ss:ff.
     */
    public function __construct(
        public readonly ?CsvColumns $columns = null,
        public readonly ?string $delimiter = null,
        public readonly ?float $frameRate = null,
    ) {
        if ($delimiter !== null) {
            CsvParser::checkDelimiter($delimiter);
        }
        if ($frameRate !== null) {
            new FrameRate($frameRate);
        }
    }
}
