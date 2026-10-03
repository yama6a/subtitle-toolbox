<?php

namespace SubtitleToolbox;

/**
 * CommentAnchors keeps each comment before the same cue while cues split, join or move.
 *
 * An anchor is the cue that a comment comes before, or null for a comment after the last cue.
 *
 * @internal
 */
final class CommentAnchors
{
    /**
     * @param SubtitleCue[]                                  $cues
     * @param array<array{text: string, beforeCueIndex: int}> $comments
     *
     * @return array<int, ?SubtitleCue>
     */
    public static function of(array $cues, array $comments): array
    {
        return array_map(fn (array $comment): ?SubtitleCue => self::anchor($cues, $comment["beforeCueIndex"]), $comments);
    }


    /**
     * @param SubtitleCue[] $cues
     */
    public static function anchor(array $cues, int $beforeCueIndex): ?SubtitleCue
    {
        foreach ($cues as $index => $cue) {
            if ($index >= $beforeCueIndex) {
                return $cue;
            }
        }

        return null;
    }


    /**
     * @param array<int, ?SubtitleCue> $anchors
     *
     * @return array<int, ?SubtitleCue>
     */
    public static function move(array $anchors, SubtitleCue $from, SubtitleCue $to): array
    {
        return array_map(fn (?SubtitleCue $anchor): ?SubtitleCue => $anchor === $from ? $to : $anchor, $anchors);
    }


    /**
     * Returns the comments sorted by their new cue index. A comment whose anchor is not in $cues goes after the last cue.
     *
     * @param SubtitleCue[]                                  $cues
     * @param array<array{text: string, beforeCueIndex: int}> $comments
     * @param array<int, ?SubtitleCue>                       $anchors
     *
     * @return list<array{text: string, beforeCueIndex: int}>
     */
    public static function comments(array $cues, array $comments, array $anchors): array
    {
        $comments = array_values($comments);
        $anchors  = array_values($anchors);
        foreach ($comments as $commentIndex => $comment) {
            $cueIndex = $anchors[$commentIndex] === null ? false : array_search($anchors[$commentIndex], $cues, true);

            $comments[$commentIndex]["beforeCueIndex"] = $cueIndex === false ? count($cues) : $cueIndex;
        }

        usort($comments, fn (array $comment1, array $comment2): int =>
            $comment1["beforeCueIndex"] <=> $comment2["beforeCueIndex"]);

        return $comments;
    }
}
