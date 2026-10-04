<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Validation\TextChecks;
use SubtitleToolbox\Validation\ValidationResult;
use SubtitleToolbox\Validation\ValidationRules;

trait Validation
{
    /**
     * Checks every cue against the rules that have a limit and returns one result per broken rule.
     *
     * @return list<ValidationResult>
     */
    public function validate(ValidationRules $rules): array
    {
        $results       = [];
        $previousEnd   = null;
        $previousStart = null;
        $expectedIndex = 0;

        if ($rules->requireCues && $this->getCues() === []) {
            $results[] = new ValidationResult(null, ValidationResult::RULE_REQUIRE_CUES, 0, null);
        }

        foreach ($this->getCues() as $cueIndex => $cue) {
            $lineLengths = [];
            foreach ($cue->getLines() as $line) {
                $length = Markup::visibleLength($line);
                if ($length > 0) {
                    $lineLengths[] = $length;
                }
            }
            $characters = array_sum($lineLengths);
            $duration   = round($cue->getEnd() - $cue->getStart(), 3);

            if ($rules->noIndexGaps && $cueIndex !== $expectedIndex) {
                $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_INDEX_GAP, $expectedIndex, null);
            }
            $expectedIndex = $cueIndex + 1;

            if ($rules->noUnsortedCues && $previousStart !== null && $cue->getStart() < $previousStart) {
                $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_UNSORTED_CUES,
                                                  round($previousStart - $cue->getStart(), 3), null);
            }
            $previousStart = $cue->getStart();

            if ($rules->noNegativeDuration && $duration < 0) {
                $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_NEGATIVE_DURATION, $duration, null);
            }

            if ($rules->noEmptyCues && $characters === 0) {
                $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_EMPTY_CUE, 0, null);
            }

            if ($rules->maxCharactersPerLine !== null) {
                foreach ($lineLengths as $length) {
                    if ($length > $rules->maxCharactersPerLine) {
                        $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_MAX_CHARACTERS_PER_LINE,
                                                          $length, $rules->maxCharactersPerLine);
                    }
                }
            }

            if ($rules->maxLinesPerCue !== null && count($lineLengths) > $rules->maxLinesPerCue) {
                $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_MAX_LINES_PER_CUE,
                                                  count($lineLengths), $rules->maxLinesPerCue);
            }

            if ($rules->maxCharactersPerSecond !== null && $characters > 0) {
                $charactersPerSecond = $duration > 0 ? $characters / $duration : INF;
                if ($charactersPerSecond > $rules->maxCharactersPerSecond) {
                    $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_MAX_CHARACTERS_PER_SECOND,
                                                      $charactersPerSecond, $rules->maxCharactersPerSecond);
                }
            }

            // Cue times have millisecond precision, so a limit such as 5/6 s must match a cue of 0.833 s.
            if ($rules->minDuration !== null && $duration < round($rules->minDuration, 3)) {
                $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_MIN_DURATION,
                                                  $duration, $rules->minDuration);
            }

            if ($rules->maxDuration !== null && $duration > round($rules->maxDuration, 3)) {
                $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_MAX_DURATION,
                                                  $duration, $rules->maxDuration);
            }

            array_push($results, ...TextChecks::check($cueIndex, $cue, $duration, $rules));

            if ($previousEnd !== null) {
                $gap = round($cue->getStart() - $previousEnd, 3);

                if ($rules->noOverlap && $gap < 0) {
                    $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_OVERLAP, -$gap, null);
                }

                if ($rules->minGap !== null && $gap >= 0 && $gap < round($rules->minGap, 3)) {
                    $results[] = new ValidationResult($cueIndex, ValidationResult::RULE_MIN_GAP, $gap, $rules->minGap);
                }
            }
            $previousEnd = max($previousEnd ?? $cue->getEnd(), $cue->getEnd());
        }

        return $results;
    }
}
