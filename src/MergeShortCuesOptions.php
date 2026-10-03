<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class MergeShortCuesOptions
{
    /**
     * Creates the settings for Subtitle::mergeShortCues(), see the README section "Merging short cues".
     */
    public function __construct(
        public readonly int $maxCharactersPerLine = 42,
        public readonly int $maxLines = 2,
        public readonly float $maxGap = 0.25,
        public readonly float $maxDuration = 7,
        public readonly float $minDuration = 1,
        public readonly ?int $minCharacters = null,
        public readonly ?float $maxCharactersPerSecond = null,
        public readonly bool $keepSentenceEnds = false,
        public readonly bool $sameSpeakerOnly = false,
    ) {
        if ($maxCharactersPerLine < 1 || $maxLines < 1) {
            throw new InvalidArgumentException("The maximum characters per line and the maximum lines must be at " .
                                               "least 1, got $maxCharactersPerLine and $maxLines.");
        }

        if ($maxGap < 0 || $minDuration < 0) {
            throw new InvalidArgumentException("The maximum gap and the minimum duration must not be negative, " .
                                               "got $maxGap and $minDuration.");
        }

        if ($maxDuration <= 0 || ($maxCharactersPerSecond !== null && $maxCharactersPerSecond <= 0)) {
            throw new InvalidArgumentException("The maximum duration and the maximum characters per second must be " .
                                               "greater than 0, got $maxDuration and " . ($maxCharactersPerSecond ?? "null") . ".");
        }

        if ($minCharacters !== null && $minCharacters < 1) {
            throw new InvalidArgumentException("The minimum characters must be at least 1 or null, got $minCharacters.");
        }
    }
}
