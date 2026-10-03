<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * CueList holds the cue list helpers that more than one trait of Subtitle or one service needs.
 *
 * @internal
 */
final class CueList
{
    /**
     * @param SubtitleCue[] $cues
     *
     * @return list<SubtitleCue>
     */
    public static function inStartOrder(array $cues): array
    {
        $cues = array_values($cues);
        usort($cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        return $cues;
    }


    /**
     * Joins the cues of $group into the first cue of $group and removes the others from $cues. The first cue ends at
     * the latest end of the group. With $joinLines, it also gets the lines of the others.
     *
     * @param SubtitleCue[]            $cues
     * @param SubtitleCue[]            $group
     * @param array<int, ?SubtitleCue> $anchors
     *
     * @return array{list<SubtitleCue>, array<int, ?SubtitleCue>} the cues and the anchors after the join
     */
    public static function join(array $cues, array $group, array $anchors, bool $joinLines): array
    {
        $group  = array_values($group);
        $joined = $group[0];
        $lines  = $joined->getLines();
        $end    = $joined->getEnd();
        foreach (array_slice($group, 1) as $cue) {
            if ($joinLines) {
                $lines = array_merge($lines, $cue->getLines());
            }
            $end     = max($end, $cue->getEnd());
            $anchors = CommentAnchors::move($anchors, $cue, $joined);
        }
        $joined->setEnd($end)->setLinesByArray($lines);

        $cues = array_values(array_filter(
            $cues,
            fn (SubtitleCue $cue): bool => $cue === $joined || !in_array($cue, $group, true)
        ));

        return [$cues, $anchors];
    }


    /**
     * @return list<string> the sorted names of the <v> speakers in the cue
     */
    public static function speakers(SubtitleCue $cue): array
    {
        preg_match_all('/<v(?:\.[^\s>]*)?\s+([^>]*)>/i', $cue->getText(), $matches);
        $speakers = array_unique(array_map("trim", $matches[1]));
        sort($speakers);

        return $speakers;
    }
}
