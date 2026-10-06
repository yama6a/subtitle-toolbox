<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\OptionChecks;

final class PlainTextWriteOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly bool $joinLines = true,              // joins the lines of a cue with a space, false keeps line breaks
        public readonly bool $joinCues = true,               // joins the cues of a paragraph with a space, false writes one per line
        public readonly float $paragraphGap = 2.0,           // seconds of silence that start a new paragraph
        public readonly bool $withTimes = false,             // starts each paragraph with its start time, [00:01:02]
    ) {
        OptionChecks::notNegative($paragraphGap, "The paragraph gap must be 0 or more seconds, got %s.");
    }
}
