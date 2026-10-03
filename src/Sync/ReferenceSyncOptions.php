<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Subtitle;

final class ReferenceSyncOptions
{
    /**
     * Creates the search settings: the reference whose cue times the target gets, the offset range in seconds, the
     * frame-rate scale search, and the split search.
     */
    public function __construct(
        public readonly Subtitle $reference,
        public readonly float $minOffset = -60,
        public readonly float $maxOffset = 60,
        public readonly bool $searchScale = true,
        public readonly int $maxSplits = 0,
        public readonly float $splitPenalty = 0.1,
    ) {
        if ($minOffset > $maxOffset) {
            throw new InvalidArgumentException("The minimum offset must not be greater than the maximum offset, " .
                                               "got $minOffset and $maxOffset.");
        }

        if ($maxSplits < 0) {
            throw new InvalidArgumentException("The maximum number of splits must not be negative, got $maxSplits.");
        }

        if ($splitPenalty < 0) {
            throw new InvalidArgumentException("The split penalty must not be negative, got $splitPenalty.");
        }
    }
}
