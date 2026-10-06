<?php

declare(strict_types=1);

namespace SubtitleToolbox\Validation;

final class ValidationViolation
{
    /**
     * Holds one broken rule.
     *
     * @internal validate() and YouTubeChapters::check() create the violations.
     *
     * @param ?int           $cueIndex the cue that breaks the rule, or null for a rule about the whole subtitle such as requireCues
     * @param int|float      $value    the measured value in the unit of the rule, for example characters, seconds or a count of lines
     * @param int|float|null $limit    the number limit from the rules, or null for a rule without a number limit such as noOverlap
     */
    public function __construct(
        public readonly ?int $cueIndex,
        public readonly ValidationRule $rule,
        public readonly int|float $value,
        public readonly int|float|null $limit,
    ) {
    }
}
