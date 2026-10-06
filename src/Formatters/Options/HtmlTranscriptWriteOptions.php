<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\OptionChecks;

final class HtmlTranscriptWriteOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly float $paragraphGap = 2.0,           // seconds of silence that start a new paragraph
    ) {
        OptionChecks::notNegative($paragraphGap, "The paragraph gap must be 0 or more seconds, got %s.");
    }
}
