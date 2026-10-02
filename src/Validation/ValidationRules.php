<?php

namespace SubtitleToolbox\Validation;

use SubtitleToolbox\FrameRate;

final class ValidationRules
{
    /**
     * Creates a rule set. A rule with the limit null or false is off.
     */
    public function __construct(
        public readonly ?float $maxCharactersPerSecond = null,
        public readonly ?int $maxCharactersPerLine = null,
        public readonly ?int $maxLinesPerCue = null,
        public readonly ?float $minDuration = null,
        public readonly ?float $maxDuration = null,
        public readonly ?float $minGap = null,
        public readonly bool $noOverlap = false,
        public readonly bool $noEmptyCues = false,
    ) {
    }


    /**
     * Returns the limits of the Netflix English (USA) Timed Text Style Guide for adult programs at the given frame rate.
     */
    public static function netflixEnglish(float $fps): self
    {
        return new self(
            maxCharactersPerSecond: 20,
            maxCharactersPerLine: 42,
            maxLinesPerCue: 2,
            minDuration: 5 / 6,
            maxDuration: 7,
            minGap: (new FrameRate($fps))->framesToSeconds(2),
            noOverlap: true,
        );
    }
}
