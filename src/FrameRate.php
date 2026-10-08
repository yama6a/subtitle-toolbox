<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class FrameRate
{
    private float $framesPerSecond;


    public function __construct(float $framesPerSecond)
    {
        self::check($framesPerSecond);

        $this->framesPerSecond = $framesPerSecond;
    }


    /**
     * Throws InvalidArgumentException with $message unless $framesPerSecond is finite and greater than 0.
     * %s in $message becomes the value.
     *
     * @internal
     */
    public static function check(float $framesPerSecond, string $message = "The frame rate must be greater than 0, got %s."): void
    {
        OptionChecks::positiveFinite($framesPerSecond, $message);
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
