<?php

namespace SubtitleToolbox\Chapters;

use SubtitleToolbox\Subtitle;

// The rules come from https://support.google.com/youtube/answer/9884579.
final class YouTubeChapters
{
    public const RULE_FIRST_CHAPTER_AT_ZERO = "firstChapterAtZero";
    public const RULE_MIN_CHAPTERS          = "minChapters";
    public const RULE_MIN_DURATION          = "minDuration";

    public const MIN_CHAPTERS = 3;
    public const MIN_DURATION = 10;


    /**
     * Lists the YouTube chapter rules that $chapters breaks, one entry per rule and chapter, in rule order.
     *
     * @return list<array{rule: string, chapterIndex: ?int, value: int|float, limit: int|float}>
     */
    public static function check(Subtitle $chapters): array
    {
        $cues   = array_values($chapters->getCues());
        $broken = [];
        if ($cues !== [] && floor($cues[0]->getStart()) > 0) {
            $broken[] = ["rule" => self::RULE_FIRST_CHAPTER_AT_ZERO, "chapterIndex" => 0, "value" => $cues[0]->getStart(), "limit" => 0];
        }
        if (count($cues) < self::MIN_CHAPTERS) {
            $broken[] = ["rule" => self::RULE_MIN_CHAPTERS, "chapterIndex" => null, "value" => count($cues), "limit" => self::MIN_CHAPTERS];
        }

        foreach ($cues as $index => $cue) {
            $duration = round($cue->getEnd() - $cue->getStart(), 3);
            // A last chapter that ends at its own start has an unknown length, because the parser did not know the video length.
            $unknown = $index === count($cues) - 1 && $duration <= 0;
            if (!$unknown && $duration < self::MIN_DURATION) {
                $broken[] = ["rule" => self::RULE_MIN_DURATION, "chapterIndex" => $index, "value" => $duration, "limit" => self::MIN_DURATION];
            }
        }

        return $broken;
    }
}
