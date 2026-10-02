<?php

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Subtitle;

final class SyncResult
{
    public function __construct(
        private readonly float $offset,
        private readonly float $scale,
        private readonly float $score,
    ) {
    }


    /**
     * Returns the seconds to add to each time after the scale.
     */
    public function getOffset(): float
    {
        return $this->offset;
    }


    /**
     * Returns the factor to multiply each time with before the offset.
     */
    public function getScale(): float
    {
        return $this->scale;
    }


    /**
     * Returns the cue time that both files share after the sync, divided by the cue time of either file, from 0 to 1.
     */
    public function getScore(): float
    {
        return $this->score;
    }


    /**
     * Scales and then shifts the cues of $target, and returns $target.
     */
    public function apply(Subtitle $target): Subtitle
    {
        if ($this->scale != 1) {
            $target->scale($this->scale);
        }

        if ($this->offset != 0) {
            $target->shift($this->offset);
        }

        return $target;
    }
}
