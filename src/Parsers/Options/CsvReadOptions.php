<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;

final class CsvReadOptions implements FormatReadOptions
{
    /** @internal */
    public const DELIMITERS = [",", ";", "\t"];


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
            self::checkDelimiter($delimiter);
        }
        if ($frameRate !== null) {
            FrameRate::check($frameRate);
        }
    }


    /**
     * Throws InvalidArgumentException for a delimiter that is not in DELIMITERS.
     *
     * @internal
     */
    public static function checkDelimiter(mixed $delimiter): void
    {
        if (!in_array($delimiter, self::DELIMITERS, true)) {
            throw new InvalidArgumentException("The CSV delimiter must be \",\", \";\" or a tab.");
        }
    }
}
