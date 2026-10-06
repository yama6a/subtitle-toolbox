<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class ShotChangeOptions
{
    public readonly float $frameRate;

    /** @var list<float> */
    public readonly array $shotChanges;

    public readonly int $snapWindowFrames;

    public readonly int $minGapFrames;

    public readonly bool $chain;

    public readonly int $minDurationFrames;


    /**
     * Creates the timing rules in frames of $frameRate, where a null $snapWindowFrames means half a second.
     * $shotChanges holds the shot change times in seconds. Without them, ShotChangeTiming::apply() only closes small gaps.
     *
     * @param list<float> $shotChanges
     */
    public function __construct(
        float $frameRate,
        array $shotChanges = [],
        ?int $snapWindowFrames = null,
        int $minGapFrames = 2,
        bool $chain = true,
        int $minDurationFrames = 20,
    ) {
        if ($frameRate <= 0) {
            throw new InvalidArgumentException("The frame rate must be greater than 0, got $frameRate.");
        }

        // At 25 fps, half a second rounds down to 12 frames, the Netflix value for 24 fps.
        $snapWindowFrames ??= (int)round($frameRate / 2, 0, PHP_ROUND_HALF_DOWN);
        if ($snapWindowFrames < 0) {
            throw new InvalidArgumentException("The snap window must not be negative, got $snapWindowFrames.");
        }

        if ($minGapFrames < 0) {
            throw new InvalidArgumentException("The minimum gap must not be negative, got $minGapFrames.");
        }

        if ($minDurationFrames < 0) {
            throw new InvalidArgumentException("The minimum duration must not be negative, got $minDurationFrames.");
        }

        $this->frameRate         = $frameRate;
        $this->shotChanges       = array_values($shotChanges);
        $this->snapWindowFrames  = $snapWindowFrames;
        $this->minGapFrames      = $minGapFrames;
        $this->chain             = $chain;
        $this->minDurationFrames = $minDurationFrames;
    }
}
