<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

class FrameRate
{
    protected float $fps;


    public function __construct(float $fps)
    {
        if ($fps <= 0) {
            throw new InvalidArgumentException("The frame rate must be greater than 0, got $fps.");
        }

        $this->fps = $fps;
    }


    public function getFps(): float
    {
        return $this->fps;
    }


    /**
     * Converts a frame number to seconds.
     */
    public function framesToSeconds(int $frames): float
    {
        return $frames / $this->fps;
    }


    /**
     * Converts seconds to the nearest frame number.
     */
    public function secondsToFrames(float $seconds): int
    {
        return (int)round($seconds * $this->fps);
    }
}
