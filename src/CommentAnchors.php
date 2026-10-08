<?php

declare(strict_types=1);

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
     * @param SubtitleCue[] $cues
     * @param list<Comment> $comments
     *
     * @return array<int, ?SubtitleCue>
     */
    public static function of(array $cues, array $comments): array
    {
        return array_map(fn (Comment $comment): ?SubtitleCue => self::anchor($cues, $comment->beforeCueIndex), $comments);
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
     * Adds the parsed cues, then puts each comment before the cue that followed it in the file.
     *
     * @param list<SubtitleCue>              $cues     in file order
     * @param list<array{0: string, 1: int}> $comments the text and the count of cues before it in the file
     */
    public static function addParsed(Subtitle $subtitle, array $cues, array $comments): Subtitle
    {
        $subtitle->addCues($cues);
        foreach ($comments as [$text, $cueCount]) {
            $anchor   = self::anchor($cues, $cueCount);
            $cueIndex = $anchor === null ? false : array_search($anchor, $subtitle->getCues(), true);
            $subtitle->addComment($text, $cueIndex === false ? count($subtitle) : $cueIndex);
        }

        return $subtitle;
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
     * Replaces each anchor with the cue that $map holds for it. A null anchor stays null.
     *
     * @param array<int, ?SubtitleCue>                    $anchors
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $map
     *
     * @return array<int, ?SubtitleCue>
     */
    public static function remap(array $anchors, \SplObjectStorage $map): array
    {
        return array_map(fn (?SubtitleCue $anchor): ?SubtitleCue => $anchor === null ? null : $map[$anchor], $anchors);
    }


    /**
     * Returns the comments sorted by their new cue index. A comment whose anchor is not in $cues goes after the last cue.
     *
     * @param SubtitleCue[]            $cues
     * @param list<Comment>            $comments
     * @param array<int, ?SubtitleCue> $anchors
     *
     * @return list<Comment>
     */
    public static function comments(array $cues, array $comments, array $anchors): array
    {
        $comments = array_values($comments);
        $anchors  = array_values($anchors);
        foreach ($comments as $commentIndex => $comment) {
            $cueIndex = $anchors[$commentIndex] === null ? false : array_search($anchors[$commentIndex], $cues, true);

            $comments[$commentIndex] = $comment->withBeforeCueIndex($cueIndex === false ? count($cues) : $cueIndex);
        }

        return self::sorted($comments);
    }


    /**
     * @param list<Comment> $comments
     *
     * @return list<Comment> the comments in the order of their cue index, ties in the given order
     */
    public static function sorted(array $comments): array
    {
        usort($comments, fn (Comment $comment1, Comment $comment2): int => $comment1->beforeCueIndex <=> $comment2->beforeCueIndex);

        return $comments;
    }
}
