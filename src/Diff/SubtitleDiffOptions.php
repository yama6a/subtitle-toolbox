<?php

declare(strict_types=1);

namespace SubtitleToolbox\Diff;

use SubtitleToolbox\OptionChecks;

final class SubtitleDiffOptions
{
    /**
     * @param float $timeTolerance    the largest time difference in seconds that counts as the same time
     * @param bool  $ignoreFormatting compare the text without tags, with entities decoded
     * @param bool  $ignoreWhitespace compare the text without spaces, tabs and line breaks
     * @param bool  $textOnly         report no timing changes
     */
    public function __construct(
        public readonly float $timeTolerance = 0.001,
        public readonly bool $ignoreFormatting = false,
        public readonly bool $ignoreWhitespace = false,
        public readonly bool $textOnly = false,
    ) {
        OptionChecks::nonNegativeFinite($timeTolerance, "The time tolerance must not be negative, got %s.");
    }
}
