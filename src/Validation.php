<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Validation\TextChecks;
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationRules;
use SubtitleToolbox\Validation\ValidationViolation;

/**
 * @internal
 */
trait Validation
{
    /**
     * Checks every cue against the rules that have a limit and returns one result per broken rule.
     *
     * @return list<ValidationViolation>
     */
    public function validate(ValidationRules $rules): array
    {
        $results       = [];
        $previousEnd   = null;
        $previousStart = null;

        if ($rules->requireCues && $this->getCues() === []) {
            $results[] = new ValidationViolation(null, ValidationRule::RequireCues, 0, null);
        }

        foreach ($this->getCues() as $cueIndex => $cue) {
            $lineLengths = LineWrapper::visibleLineLengths($cue->getLines());
            $characters = array_sum($lineLengths);
            $duration   = Timecode::roundToMilliseconds($cue->getEnd() - $cue->getStart());

            if ($rules->noUnsortedCues && $previousStart !== null && $cue->getStart() < $previousStart) {
                $results[] = new ValidationViolation($cueIndex, ValidationRule::NoUnsortedCues,
                                                  Timecode::roundToMilliseconds($previousStart - $cue->getStart()), null);
            }
            $previousStart = $cue->getStart();

            if ($rules->noNegativeDuration && $duration < 0) {
                $results[] = new ValidationViolation($cueIndex, ValidationRule::NoNegativeDuration, $duration, null);
            }

            if ($rules->noEmptyCues && $characters === 0) {
                $results[] = new ValidationViolation($cueIndex, ValidationRule::NoEmptyCues, 0, null);
            }

            if ($rules->maxCharactersPerLine !== null) {
                foreach ($lineLengths as $length) {
                    if ($length > $rules->maxCharactersPerLine) {
                        $results[] = new ValidationViolation($cueIndex, ValidationRule::MaxCharactersPerLine,
                                                          $length, $rules->maxCharactersPerLine);
                    }
                }
            }

            if ($rules->maxLinesPerCue !== null && count($lineLengths) > $rules->maxLinesPerCue) {
                $results[] = new ValidationViolation($cueIndex, ValidationRule::MaxLinesPerCue,
                                                  count($lineLengths), $rules->maxLinesPerCue);
            }

            if ($rules->maxCharactersPerSecond !== null && $characters > 0) {
                $charactersPerSecond = LineWrapper::charactersPerSecond($characters, $duration);
                if ($charactersPerSecond > $rules->maxCharactersPerSecond) {
                    $results[] = new ValidationViolation($cueIndex, ValidationRule::MaxCharactersPerSecond,
                                                      $charactersPerSecond, $rules->maxCharactersPerSecond);
                }
            }

            // Cue times have millisecond precision, so a limit such as 5/6 s must match a cue of 0.833 s.
            if ($rules->minDuration !== null && $duration < Timecode::roundToMilliseconds($rules->minDuration)) {
                $results[] = new ValidationViolation($cueIndex, ValidationRule::MinDuration,
                                                  $duration, $rules->minDuration);
            }

            if ($rules->maxDuration !== null && $duration > Timecode::roundToMilliseconds($rules->maxDuration)) {
                $results[] = new ValidationViolation($cueIndex, ValidationRule::MaxDuration,
                                                  $duration, $rules->maxDuration);
            }

            array_push($results, ...TextChecks::check($cueIndex, $cue, $duration, $rules));

            if ($previousEnd !== null) {
                $gap = Timecode::roundToMilliseconds($cue->getStart() - $previousEnd);

                if ($rules->noOverlap && $gap < 0) {
                    $results[] = new ValidationViolation($cueIndex, ValidationRule::NoOverlap, -$gap, null);
                }

                if ($rules->minGap !== null && $gap >= 0 && $gap < Timecode::roundToMilliseconds($rules->minGap)) {
                    $results[] = new ValidationViolation($cueIndex, ValidationRule::MinGap, $gap, $rules->minGap);
                }
            }
            $previousEnd = max($previousEnd ?? $cue->getEnd(), $cue->getEnd());
        }

        return $results;
    }
}
