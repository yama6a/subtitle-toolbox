<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class JsonWriteOptions implements FormatWriteOptions
{
    /**
     * @param bool $prettyPrint    Indent the JSON and ends it with a line break.
     * @param bool $withFormatData Write the format data of the subtitle and its cues.
     */
    public function __construct(
        public readonly bool $prettyPrint = false,
        public readonly bool $withFormatData = true,
    ) {
    }
}
