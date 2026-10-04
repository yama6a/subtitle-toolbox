<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

use SubtitleToolbox\CueLimits;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class ResegmentOptions
{
    /**
     * Creates the settings for Resegmenter::apply().
     *
     * @param CueLimits $limits     the limits of each new cue
     * @param float     $maxWordGap seconds between 2 words of one cue. ResegmentMode::ByWords only
     */
    public function __construct(
        public readonly ResegmentMode $mode,
        public readonly CueLimits $limits = new CueLimits(),
        public readonly float $maxWordGap = 0.6,
    ) {
        if ($maxWordGap < 0) {
            throw new InvalidArgumentException("The maximum word gap must not be negative, got $maxWordGap.");
        }
    }
}
