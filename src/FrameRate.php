<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class FrameRate
{
    private float $framesPerSecond;


    public function __construct(float $framesPerSecond)
    {
        if ($framesPerSecond <= 0) {
            throw new InvalidArgumentException("The frame rate must be greater than 0, got $framesPerSecond.");
        }

        $this->framesPerSecond = $framesPerSecond;
    }


    public function getFramesPerSecond(): float
    {
        return $this->framesPerSecond;
    }


    public function framesToSeconds(int $frames): float
    {
        return $frames / $this->framesPerSecond;
    }


    /**
     * Rounds to the nearest frame.
     */
    public function secondsToFrames(float $seconds): int
    {
        return (int)round($seconds * $this->framesPerSecond);
    }
}
