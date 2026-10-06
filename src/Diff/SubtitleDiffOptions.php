<?php

declare(strict_types=1);

namespace SubtitleToolbox\Diff;

use SubtitleToolbox\OptionChecks;

final class SubtitleDiffOptions
{
    /**
     * Creates the compare settings: the largest time difference in seconds that counts as the same time, and what text differences to ignore.
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
