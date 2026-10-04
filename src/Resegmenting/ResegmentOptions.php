<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class ResegmentOptions
{
    /**
     * Creates the settings for Resegmenter::apply(). $maxWordGap applies to ResegmentMode::ByWords only.
     */
    public function __construct(
        public readonly ResegmentMode $mode,
        public readonly int $maxCharactersPerLine = 42,
        public readonly int $maxLines = 2,
        public readonly float $maxDuration = 7,
        public readonly float $minDuration = 1,
        public readonly ?float $maxCharactersPerSecond = null,
        public readonly float $maxWordGap = 0.6,
    ) {
        if ($maxCharactersPerLine < 1 || $maxLines < 1) {
            throw new InvalidArgumentException("The maximum characters per line and the maximum lines must be at " .
                                               "least 1, got $maxCharactersPerLine and $maxLines.");
        }

        if ($minDuration < 0 || $maxWordGap < 0) {
            throw new InvalidArgumentException("The minimum duration and the maximum word gap must not be negative, " .
                                               "got $minDuration and $maxWordGap.");
        }

        if ($maxDuration <= 0 || ($maxCharactersPerSecond !== null && $maxCharactersPerSecond <= 0)) {
            throw new InvalidArgumentException("The maximum duration and the maximum characters per second must be " .
                                               "greater than 0, got $maxDuration and " . ($maxCharactersPerSecond ?? "null") . ".");
        }
    }
}
