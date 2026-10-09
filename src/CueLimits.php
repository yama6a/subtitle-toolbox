<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Validation\ValidationRules;

/**
 * The size and time limits of one cue that Subtitle::mergeShortCues() and Resegmenter keep. The defaults of
 * $maxCharactersPerLine, $maxLinesPerCue and $maxDuration are the Netflix limits for English: 42, 2 and 7 s.
 */
final class CueLimits
{
    /**
     * @param int    $maxCharactersPerLine   visible characters
     * @param int    $maxLinesPerCue         lines per cue
     * @param float  $minDuration            seconds, at most $maxDuration
     * @param float  $maxDuration            seconds
     * @param ?float $maxCharactersPerSecond visible characters of all lines divided by the duration, null for no limit
     */
    public function __construct(
        public readonly int $maxCharactersPerLine = ValidationRules::NETFLIX_MAX_CHARACTERS_PER_LINE,
        public readonly int $maxLinesPerCue = ValidationRules::NETFLIX_MAX_LINES_PER_CUE,
        public readonly float $minDuration = 1,
        public readonly float $maxDuration = ValidationRules::NETFLIX_MAX_DURATION,
        public readonly ?float $maxCharactersPerSecond = null,
    ) {
        if ($maxCharactersPerLine < 1 || $maxLinesPerCue < 1) {
            throw new InvalidArgumentException("The maximum characters per line and the maximum lines must be at " .
                                               "least 1, got $maxCharactersPerLine and $maxLinesPerCue.");
        }

        OptionChecks::nonNegativeFinite($minDuration, "The minimum duration must not be negative, got %s.");

        if (!OptionChecks::isPositiveFinite($maxDuration)
            || ($maxCharactersPerSecond !== null && !OptionChecks::isPositiveFinite($maxCharactersPerSecond))) {
            throw new InvalidArgumentException("The maximum duration and the maximum characters per second must be " .
                                               "greater than 0, got " . OptionChecks::text($maxDuration) . " and " .
                                               ($maxCharactersPerSecond === null ? "null" : OptionChecks::text($maxCharactersPerSecond)) . ".");
        }

        if ($minDuration > $maxDuration) {
            throw new InvalidArgumentException("The minimum duration must not be greater than the maximum duration, got " .
                                               OptionChecks::text($minDuration) . " and " . OptionChecks::text($maxDuration) . ".");
        }
    }
}
