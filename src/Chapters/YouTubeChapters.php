<?php

declare(strict_types=1);

namespace SubtitleToolbox\Chapters;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Validation\ValidationRule;
use SubtitleToolbox\Validation\ValidationViolation;

// The rules come from https://support.google.com/youtube/answer/9884579.
final class YouTubeChapters
{
    public const MIN_CHAPTERS = 3;
    public const MIN_DURATION = 10;


    /**
     * Lists the YouTube chapter rules that $chapters breaks, one violation per rule and chapter. The order is
     * FirstChapterAtZero, then MinChapters, then MinDuration by chapter. The cue index is the chapter index, or null for MinChapters.
     *
     * @return list<ValidationViolation>
     */
    public static function check(Subtitle $chapters): array
    {
        $cues   = array_values($chapters->getCues());
        $broken = [];
        if ($cues !== [] && floor($cues[0]->getStart()) > 0) {
            $broken[] = new ValidationViolation(0, ValidationRule::FirstChapterAtZero, $cues[0]->getStart(), 0);
        }
        if (count($cues) < self::MIN_CHAPTERS) {
            $broken[] = new ValidationViolation(null, ValidationRule::MinChapters, count($cues), self::MIN_CHAPTERS);
        }

        foreach ($cues as $index => $cue) {
            $duration = round($cue->getEnd() - $cue->getStart(), 3);
            // A last chapter that ends at its own start has an unknown length, because the parser did not know the video length.
            $unknown = $index === count($cues) - 1 && $duration <= 0;
            if (!$unknown && $duration < self::MIN_DURATION) {
                $broken[] = new ValidationViolation($index, ValidationRule::MinDuration, $duration, self::MIN_DURATION);
            }
        }

        return $broken;
    }
}
