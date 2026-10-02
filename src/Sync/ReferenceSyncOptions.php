<?php

namespace SubtitleToolbox\Sync;

use InvalidArgumentException;

final class ReferenceSyncOptions
{
    /**
     * Creates the search settings: the offset range in seconds, and whether to try frame-rate scale factors.
     */
    public function __construct(
        public readonly float $minOffset = -60,
        public readonly float $maxOffset = 60,
        public readonly bool $searchScale = true,
    ) {
        if ($minOffset > $maxOffset) {
            throw new InvalidArgumentException("The minimum offset must not be greater than the maximum offset, " .
                                               "got $minOffset and $maxOffset.");
        }
    }
}
