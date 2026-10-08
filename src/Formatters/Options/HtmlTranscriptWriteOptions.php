<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\OptionChecks;

final class HtmlTranscriptWriteOptions implements FormatWriteOptions
{
    /**
     * @param float $paragraphGap Seconds of silence that start a new paragraph.
     */
    public function __construct(
        public readonly float $paragraphGap = 2.0,
    ) {
        OptionChecks::notNegative($paragraphGap, "The paragraph gap must be 0 or more seconds, got %s.");
    }
}
