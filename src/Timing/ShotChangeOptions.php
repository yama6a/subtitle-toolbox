<?php

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class ShotChangeOptions
{
    public readonly float $frameRate;

    /** @var list<float> */
    public readonly array $shotChanges;

    public readonly int $snapWindow;

    public readonly int $minGapFrames;

    public readonly bool $chain;

    public readonly int $minDuration;


    /**
     * Creates the timing rules in frames of $frameRate, where a null $snapWindow means half a second. $shotChanges
     * holds the shot change times in seconds. Without them, ShotChangeTiming::apply() only closes small gaps.
     *
     * @param list<float> $shotChanges
     */
    public function __construct(
        float $frameRate,
        array $shotChanges = [],
        ?int $snapWindow = null,
        int $minGapFrames = 2,
        bool $chain = true,
        int $minDuration = 20,
    ) {
        if ($frameRate <= 0) {
            throw new InvalidArgumentException("The frame rate must be greater than 0, got $frameRate.");
        }

        // At 25 fps, half a second rounds down to 12 frames, the Netflix value for 24 fps.
        $snapWindow ??= (int)round($frameRate / 2, 0, PHP_ROUND_HALF_DOWN);
        if ($snapWindow < 0) {
            throw new InvalidArgumentException("The snap window must not be negative, got $snapWindow.");
        }

        if ($minGapFrames < 0) {
            throw new InvalidArgumentException("The minimum gap must not be negative, got $minGapFrames.");
        }

        if ($minDuration < 0) {
            throw new InvalidArgumentException("The minimum duration must not be negative, got $minDuration.");
        }

        $this->frameRate    = $frameRate;
        $this->shotChanges  = array_values($shotChanges);
        $this->snapWindow   = $snapWindow;
        $this->minGapFrames = $minGapFrames;
        $this->chain        = $chain;
        $this->minDuration  = $minDuration;
    }
}
