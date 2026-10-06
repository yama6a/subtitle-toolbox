<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class MergeShortCuesOptions
{
    /**
     * Creates the settings for Subtitle::mergeShortCues(), see docs/editing.md#short-cues.
     *
     * @param CueLimits $limits        a cue shorter than $limits->minDuration is short. A joined cue must keep the other limits
     * @param float     $maxGap        seconds between the 2 cues
     * @param ?int      $minCharacters a cue with fewer visible characters is short too
     */
    public function __construct(
        public readonly CueLimits $limits = new CueLimits(),
        public readonly float $maxGap = 0.25,
        public readonly ?int $minCharacters = null,
        public readonly bool $keepSentenceEnds = false,
        public readonly bool $mergeSameSpeakerAnyDuration = false,
    ) {
        OptionChecks::nonNegativeFinite($maxGap, "The maximum gap must not be negative, got %s.");

        if ($minCharacters !== null && $minCharacters < 1) {
            throw new InvalidArgumentException("The minimum characters must be at least 1 or null, got $minCharacters.");
        }
    }
}
