<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\OptionChecks;

final class PlainTextWriteOptions implements FormatWriteOptions
{
    /**
     * @param bool  $joinLines    Join the lines of a cue with a space. False keeps the line breaks.
     * @param bool  $joinCues     Join the cues of a paragraph with a space. False writes one cue per line.
     * @param float $paragraphGap Seconds of silence that start a new paragraph.
     * @param bool  $withTimes    Start each paragraph with its start time, such as [00:01:02].
     */
    public function __construct(
        public readonly bool $joinLines = true,
        public readonly bool $joinCues = true,
        public readonly float $paragraphGap = 2.0,
        public readonly bool $withTimes = false,
    ) {
        OptionChecks::notNegative($paragraphGap, "The paragraph gap must be 0 or more seconds, got %s.");
    }
}
