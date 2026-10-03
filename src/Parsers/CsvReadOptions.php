<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * The read settings of CSV and TSV tables.
 */
final class CsvReadOptions implements FormatReadOptions
{
    /**
     * @param ?CsvColumns $columns   The column layout. Null reads the columns by their header names.
     * @param ?string     $delimiter ",", ";" or a tab. Null detects it from the first line.
     */
    public function __construct(
        public readonly ?CsvColumns $columns = null,
        public readonly ?string $delimiter = null,
    ) {
        if ($delimiter !== null) {
            CsvParser::checkDelimiter($delimiter);
        }
    }
}
