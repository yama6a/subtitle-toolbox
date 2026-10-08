<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\Subtitle;

final class ReferenceSyncOptions
{
    /** Largest offset in seconds, before or after: one day. */
    public const MAX_OFFSET = 86400;

    /** Largest distance in seconds between minOffset and maxOffset. The split search needs memory for each 0.1 s of it. */
    public const MAX_OFFSET_RANGE = 7200;

    /** Most splits. A split search with 10 splits over the largest offset range takes about 40 s. */
    public const MAX_SPLITS = 10;

    /**
     * @param Subtitle $reference    the subtitle whose cue times the target gets
     * @param float    $minOffset    the lowest offset in seconds that the search tries
     * @param float    $maxOffset    the highest offset in seconds that the search tries
     * @param bool     $searchScale  try the frame-rate factors, or keep the scale at 1 when false
     * @param int      $maxSplits    the most points where the offset jumps. 0 turns the split search off
     * @param float    $splitPenalty the score that a split must add to stay
     */
    public function __construct(
        public readonly Subtitle $reference,
        public readonly float $minOffset = -60,
        public readonly float $maxOffset = 60,
        public readonly bool $searchScale = true,
        public readonly int $maxSplits = 0,
        public readonly float $splitPenalty = 0.1,
    ) {
        foreach (["minimum" => $minOffset, "maximum" => $maxOffset] as $name => $offset) {
            OptionChecks::between($offset, -self::MAX_OFFSET, self::MAX_OFFSET, "The $name offset must be from -" .
                                  self::MAX_OFFSET . " to " . self::MAX_OFFSET . " seconds, got %s.");
        }

        if ($minOffset > $maxOffset) {
            throw new InvalidArgumentException("The minimum offset must not be greater than the maximum offset, " .
                                               "got $minOffset and $maxOffset.");
        }

        if ($maxOffset - $minOffset > self::MAX_OFFSET_RANGE) {
            throw new InvalidArgumentException("The minimum and the maximum offset must be at most " . self::MAX_OFFSET_RANGE .
                                               " seconds apart, got $minOffset and $maxOffset.");
        }

        if ($maxSplits < 0 || $maxSplits > self::MAX_SPLITS) {
            throw new InvalidArgumentException("The maximum number of splits must be from 0 to " . self::MAX_SPLITS . ", got $maxSplits.");
        }

        OptionChecks::nonNegativeFinite($splitPenalty, "The split penalty must be a finite number of 0 or more, got %s.");
    }
}
