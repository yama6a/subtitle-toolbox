<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Formatters\FormatWriteOptions;

/**
 * The settings that every formatter reads. $format holds the settings of one format, such as CsvOptions.
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
