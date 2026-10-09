<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * A time in the subtitle and the time where it belongs, for syncByPoints().
 */
final readonly class SyncPoint
{
    /**
     * @param float $oldSeconds the time in the subtitle now
     * @param float $newSeconds the time where $oldSeconds belongs
     */
    public function __construct(
        public float $oldSeconds,
        public float $newSeconds,
    ) {
        OptionChecks::finite($oldSeconds, "The old time of a sync point must be a finite number, got %s.");
        OptionChecks::finite($newSeconds, "The new time of a sync point must be a finite number, got %s.");
    }
}
