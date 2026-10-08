<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * @internal
 */
trait Retiming
{
    /**
     * Shifts the cues that start at or after $fromTime by $seconds, or all cues when $fromTime is null.
     * The word timestamps in the cue text move with the cue. A time that becomes negative becomes 0, and the cue stays.
     */
    public function shift(float $seconds, ?float $fromTime = null): self
    {
        return $this->retimingApplyLinearCorrection(1, $seconds, $fromTime);
    }


    /**
     * Multiplies the start and end time and the word timestamps of every cue by $factor.
     */
    public function scale(float $factor): self
    {
        OptionChecks::positiveFinite($factor, "The scale factor must be greater than 0, got %s.");

        return $this->retimingApplyLinearCorrection($factor, 0);
    }


    /**
     * Converts the cue times from a video at the frame rate $from to the same video at the frame rate $to.
     */
    public function convertFrameRate(float $from, float $to): self
    {
        return $this->scale((new FrameRate($from))->getFramesPerSecond() / (new FrameRate($to))->getFramesPerSecond());
    }


    /**
     * Moves time $oldA to $newA and time $oldB to $newB, and corrects all other times and the word timestamps linearly.
     * A time that becomes negative becomes 0, and the cue stays.
     */
    public function syncByTwoPoints(float $oldA, float $newA, float $oldB, float $newB): self
    {
        if ($oldA === $oldB) {
            throw new InvalidArgumentException("The two old times must differ, got $oldA twice.");
        }

        $factor = ($newB - $newA) / ($oldB - $oldA);
        if ($factor <= 0) {
            throw new InvalidArgumentException("The two new times must be in the same order as the two old times.");
        }

        return $this->retimingApplyLinearCorrection($factor, $newA - $oldA * $factor);
    }


    private function retimingApplyLinearCorrection(float $factor, float $offset, ?float $fromTime = null): self
    {
        foreach ($this->getCues() as $cue) {
            if ($fromTime !== null && $cue->getStart() < $fromTime) {
                continue;
            }

            $cue->mapTimes(fn (float $time): float => $time * $factor + $offset);
        }

        return $this;
    }
}
