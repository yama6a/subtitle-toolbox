<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class JsonWriteOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly bool $prettyPrint = false,           // indents the JSON and ends it with a line break
        public readonly bool $withFormatData = true,         // writes the format data of the subtitle and its cues
    ) {
    }
}
