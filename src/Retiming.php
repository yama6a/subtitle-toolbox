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
        return $this->applyLinearCorrection(1, $seconds, $fromTime);
    }


    /**
     * Multiplies the start and end time and the word timestamps of every cue by $factor.
     */
    public function scale(float $factor): self
    {
        if ($factor <= 0) {
            throw new InvalidArgumentException("The scale factor must be greater than 0, got $factor.");
        }

        return $this->applyLinearCorrection($factor, 0);
    }


    /**
     * Converts the cue times from a video at $fromFps to the same video at $toFps.
     */
    public function convertFrameRate(float $fromFps, float $toFps): self
    {
        return $this->scale((new FrameRate($fromFps))->getFps() / (new FrameRate($toFps))->getFps());
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

        return $this->applyLinearCorrection($factor, $newA - $oldA * $factor);
    }


    private function applyLinearCorrection(float $factor, float $offset, ?float $fromTime = null): self
    {
        foreach ($this->getCues() as $cue) {
            if ($fromTime !== null && $cue->getStart() < $fromTime) {
                continue;
            }

            $start = max(0, $cue->getStart() * $factor + $offset);
            $end   = max(0, $cue->getEnd() * $factor + $offset);
            $cue->setStart($start)->setEnd($end)->mapWordTimestamps(fn (float $time): float => $time * $factor + $offset);
        }

        return $this;
    }
}
