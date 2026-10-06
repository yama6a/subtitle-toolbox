<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * The size and time limits of one cue that Subtitle::mergeShortCues() and Resegmenter keep. The defaults are the
 * Netflix limits for English.
 */
final class CueLimits
{
    /**
     * @param int    $maxCharactersPerLine   visible characters
     * @param int    $maxLinesPerCue         lines per cue
     * @param float  $minDuration            seconds
     * @param float  $maxDuration            seconds
     * @param ?float $maxCharactersPerSecond visible characters of all lines divided by the duration, null for no limit
     */
    public function __construct(
        public readonly int $maxCharactersPerLine = 42,
        public readonly int $maxLinesPerCue = 2,
        public readonly float $minDuration = 1,
        public readonly float $maxDuration = 7,
        public readonly ?float $maxCharactersPerSecond = null,
    ) {
        if ($maxCharactersPerLine < 1 || $maxLinesPerCue < 1) {
            throw new InvalidArgumentException("The maximum characters per line and the maximum lines must be at " .
                                               "least 1, got $maxCharactersPerLine and $maxLinesPerCue.");
        }

        if ($minDuration < 0) {
            throw new InvalidArgumentException("The minimum duration must not be negative, got $minDuration.");
        }

        if ($maxDuration <= 0 || ($maxCharactersPerSecond !== null && $maxCharactersPerSecond <= 0)) {
            throw new InvalidArgumentException("The maximum duration and the maximum characters per second must be " .
                                               "greater than 0, got $maxDuration and " . ($maxCharactersPerSecond ?? "null") . ".");
        }
    }
}
