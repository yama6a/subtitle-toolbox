<?php

namespace SubtitleToolbox\Validation;

final class ValidationResult
{
    public const RULE_MAX_CHARACTERS_PER_SECOND = "maxCharactersPerSecond";
    public const RULE_MAX_CHARACTERS_PER_LINE   = "maxCharactersPerLine";
    public const RULE_MAX_LINES_PER_CUE         = "maxLinesPerCue";
    public const RULE_MIN_DURATION              = "minDuration";
    public const RULE_MAX_DURATION              = "maxDuration";
    public const RULE_MIN_GAP                   = "minGap";
    public const RULE_OVERLAP                   = "noOverlap";
    public const RULE_EMPTY_CUE                 = "noEmptyCues";


    public function __construct(
        private readonly int $cueIndex,
        private readonly string $rule,
        private readonly int|float $value,
        private readonly int|float|null $limit,
    ) {
    }


    public function getCueIndex(): int
    {
        return $this->cueIndex;
    }


    /**
     * Returns one of the RULE_* constants.
     */
    public function getRule(): string
    {
        return $this->rule;
    }


    /**
     * Returns the measured value in the unit of the rule: characters, lines, characters per second or seconds.
     */
    public function getValue(): int|float
    {
        return $this->value;
    }


    /**
     * Returns the limit from the rules, or null for the overlap and empty cue rules.
     */
    public function getLimit(): int|float|null
    {
        return $this->limit;
    }
}
