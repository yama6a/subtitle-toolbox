<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Formatters\Options\FormatWriteOptions;

/**
 * The write settings for all formats. $format holds the settings of one format, such as CsvWriteOptions.
 * EBU STL and PGS ignore $lineEnding and $bom. Only the formatters of ASS, EBU STL, iTT, MicroDVD, SAMI, SubRip,
 * TTML and WebVTT read $stripTags. Subtitle::toString() reads $skipImageCues.
 */
final class WriteOptions
{
    public function __construct(
        public readonly LineEnding $lineEnding = LineEnding::Lf,
        public readonly ?bool $bom = null,                 // null keeps the default of the format
        public readonly bool $stripTags = false,
        public readonly bool $skipImageCues = false,
        public readonly ?FormatWriteOptions $format = null,
    ) {
    }
}
