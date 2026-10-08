<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

use SubtitleToolbox\CueLimits;
use SubtitleToolbox\OptionChecks;

final class ResegmentOptions
{
    /**
     * @param ResegmentMode $mode       how the cues change
     * @param CueLimits     $limits     the limits of each new cue
     * @param float         $maxWordGap a gap of this many seconds or more between 2 words starts a new cue. ResegmentMode::ByWords only
     */
    public function __construct(
        public readonly ResegmentMode $mode,
        public readonly CueLimits $limits = new CueLimits(),
        public readonly float $maxWordGap = 0.6,
    ) {
        OptionChecks::nonNegativeFinite($maxWordGap, "The maximum word gap must not be negative, got %s.");
    }
}
