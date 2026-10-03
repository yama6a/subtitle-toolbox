<?php

declare(strict_types=1);

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

    public const RULE_NO_DOUBLE_SPACES              = "noDoubleSpaces";
    public const RULE_NO_LEADING_OR_TRAILING_SPACES = "noLeadingOrTrailingSpaces";
    public const RULE_NO_UNBALANCED_TAGS            = "noUnbalancedTags";
    public const RULE_DIALOGUE_DASH_STYLE           = "dialogueDashStyle";
    public const RULE_MAX_SPEAKERS_PER_CUE          = "maxSpeakersPerCue";
    public const RULE_MAX_WORDS_PER_MINUTE          = "maxWordsPerMinute";
    public const RULE_MIN_SECONDS_PER_WORD          = "minSecondsPerWord";
    public const RULE_ALLOWED_CHARACTERS            = "allowedCharacters";
    public const RULE_NO_ALL_CAPS_LINES             = "noAllCapsLines";

    public const RULE_REQUIRE_CUES        = "requireCues";
    public const RULE_UNSORTED_CUES       = "noUnsortedCues";
    public const RULE_NEGATIVE_DURATION   = "noNegativeDuration";
    public const RULE_INDEX_GAP           = "noIndexGaps";


    public function __construct(
        private readonly ?int $cueIndex,
        private readonly string $rule,
        private readonly int|float $value,
        private readonly int|float|null $limit,
    ) {
    }


    /**
     * Returns the index of the cue that breaks the rule, or null for a rule about the whole subtitle such as requireCues.
     */
    public function getCueIndex(): ?int
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
     * Returns the measured value in the unit of the rule, for example characters, seconds or a count of lines.
     */
    public function getValue(): int|float
    {
        return $this->value;
    }


    /**
     * Returns the number limit from the rules, or null for a rule without a number limit such as noOverlap.
     */
    public function getLimit(): int|float|null
    {
        return $this->limit;
    }
}
