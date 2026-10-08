<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\OptionChecks;

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
     * @param float       $frameRate         the frame rate of the video. The other rules count frames of it
     * @param list<float> $shotChanges       the shot change times in seconds. Without them, apply() only closes gaps shorter than snapWindowFrames frames
     * @param ?int        $snapWindowFrames  the frames within which a cue time moves to a shot change, or null for half a second
     * @param int         $minGapFrames      the frames between a cue and the next cue or shot change
     * @param bool        $chain             close the gaps between cues
     * @param int         $minDurationFrames the least frames of a cue after a move
     */
    public function __construct(
        float $frameRate,
        array $shotChanges = [],
        ?int $snapWindowFrames = null,
        int $minGapFrames = 2,
        bool $chain = true,
        int $minDurationFrames = 20,
    ) {
        FrameRate::check($frameRate);
        foreach ($shotChanges as $shotChange) {
            OptionChecks::finite($shotChange, "The shot change time must be a finite number, got %s.");
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
