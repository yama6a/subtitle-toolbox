<?php

namespace SubtitleToolbox;

use InvalidArgumentException;

trait CueEditing
{
    /**
     * Appends copies of the cues of $other, moved by $offset seconds. Metadata and format data of $this win.
     */
    public function merge(Subtitle $other, float $offset = 0): self
    {
        $ownAnchors   = $this->getCommentAnchors();
        $otherCues    = [];
        $otherAnchors = [];
        foreach ($other->getCues() as $index => $cue) {
            $otherCues[$index] = (clone $cue)
                ->setStart(max(0, $cue->getStart() + $offset))
                ->setEnd(max(0, $cue->getEnd() + $offset));
        }
        foreach ($other->getComments() as $comment) {
            $otherAnchors[] = $this->findAnchor($otherCues, $comment["beforeCueIndex"]);
        }

        $firstOtherCue = reset($otherCues) ?: null;
        foreach ($ownAnchors as $commentIndex => $anchor) {
            $ownAnchors[$commentIndex] = $anchor ?? $firstOtherCue;
        }

        $comments = array_merge($this->comments, $other->getComments());
        $cues     = array_merge(array_values($this->cues), array_values($otherCues));
        usort($cues, fn (SubtitleCue $cue1, SubtitleCue $cue2): int => $cue1->getStart() <=> $cue2->getStart());

        $this->cues       = $cues;
        $this->metadata   = $this->metadata + $other->getAllMetadata();
        $this->formatData = $this->formatData + $other->formatData;
        $this->setCommentsByAnchors($comments, array_merge($ownAnchors, $otherAnchors));

        return $this;
    }


    /**
     * Returns a copy with the cues from $from to $to seconds, cut at both times, and the comments before these cues.
     */
    public function slice(float $from, float $to, bool $moveToZero = false): self
    {
        if ($from > $to) {
            throw new InvalidArgumentException("The slice start $from must not be after the slice end $to.");
        }

        $anchors = $this->getCommentAnchors();
        $slice   = clone $this;
        $cues    = [];
        $copies  = new \SplObjectStorage();
        foreach ($this->cues as $cue) {
            if ($cue->getEnd() <= $from || $cue->getStart() >= $to) {
                continue;
            }

            $copy = (clone $cue)
                ->setStart(max($cue->getStart(), $from) - ($moveToZero ? $from : 0))
                ->setEnd(min($cue->getEnd(), $to) - ($moveToZero ? $from : 0));

            $cues[]       = $copy;
            $copies[$cue] = $copy;
        }

        $lastCue    = end($this->cues) ?: null;
        $keepsEnd   = $lastCue !== null && isset($copies[$lastCue]);
        $comments   = [];
        $newAnchors = [];
        foreach ($this->comments as $commentIndex => $comment) {
            $anchor = $anchors[$commentIndex];
            if ($anchor === null ? $keepsEnd : isset($copies[$anchor])) {
                $comments[]   = $comment;
                $newAnchors[] = $anchor === null ? null : $copies[$anchor];
            }
        }

        $slice->cues = $cues;
        $slice->setCommentsByAnchors($comments, $newAnchors);

        return $slice;
    }


    /**
     * Splits the cue at $index at $at seconds. The first cue keeps the lines up to line $splitAfterLine, counted from 1.
     */
    public function splitCue(int $index, float $at, int $splitAfterLine): self
    {
        $cue = $this->getEditableCue($index);
        if ($at <= $cue->getStart() || $at >= $cue->getEnd()) {
            throw new InvalidArgumentException("Cannot split cue $index at $at - the time must be after the cue " .
                                               "start {$cue->getStart()} and before the cue end {$cue->getEnd()}.");
        }

        $lines = $cue->getLines();
        if ($splitAfterLine < 1 || $splitAfterLine >= count($lines)) {
            throw new InvalidArgumentException("Cannot split cue $index after line $splitAfterLine - " .
                                               "the cue has " . count($lines) . " lines.");
        }

        $anchors = $this->getCommentAnchors();
        $second  = (clone $cue)
            ->setIdentifier(null)
            ->setStart($at)
            ->setLinesByArray(array_slice($lines, $splitAfterLine));
        $cue->setEnd($at)->setLinesByArray(array_slice($lines, 0, $splitAfterLine));

        $cues     = array_values($this->cues);
        $position = array_search($cue, $cues, true);
        array_splice($cues, $position + 1, 0, [$second]);

        $this->cues = $cues;
        $this->setCommentsByAnchors($this->comments, $anchors);

        return $this;
    }


    /**
     * Joins the cues from $first to $last into one cue that holds the lines of all of them.
     * The joined cue keeps the identifier, alignment and format data of cue $first.
     */
    public function joinCues(int $first, int $last): self
    {
        if ($first >= $last) {
            throw new InvalidArgumentException("Cannot join cues $first to $last - the first index must be " .
                                               "lower than the last index.");
        }
        $this->getEditableCue($first);
        $this->getEditableCue($last);

        $anchors = $this->getCommentAnchors();
        $group   = array_filter(
            $this->cues,
            fn (int $index): bool => $index >= $first && $index <= $last,
            ARRAY_FILTER_USE_KEY
        );

        $anchors = $this->joinGroup(array_values($group), $anchors, true);
        $this->setCommentsByAnchors($this->comments, $anchors);

        return $this;
    }


    /**
     * Joins each run of adjacent cues with the same text where one cue ends at the start time of the next.
     */
    public function removeDuplicateCues(): self
    {
        $anchors = $this->getCommentAnchors();
        $groups  = [];
        $group   = [];
        foreach ($this->cues as $cue) {
            $previous = end($group) ?: null;
            if ($previous !== null && $previous->getText() === $cue->getText()
                && $previous->getEnd() === $cue->getStart()) {
                $group[] = $cue;
                continue;
            }

            $groups[] = $group;
            $group    = [$cue];
        }
        $groups[] = $group;

        foreach ($groups as $group) {
            if (count($group) > 1) {
                $anchors = $this->joinGroup($group, $anchors, false);
            }
        }
        $this->setCommentsByAnchors($this->comments, $anchors);

        return $this;
    }


    /**
     * @param SubtitleCue[] $group
     * @param array<int, ?SubtitleCue> $anchors
     *
     * @return array<int, ?SubtitleCue>
     */
    private function joinGroup(array $group, array $anchors, bool $joinLines): array
    {
        $joined = $group[0];
        $lines  = $joined->getLines();
        $end    = $joined->getEnd();
        foreach (array_slice($group, 1) as $cue) {
            if ($joinLines) {
                $lines = array_merge($lines, $cue->getLines());
            }
            $end = max($end, $cue->getEnd());

            foreach ($anchors as $commentIndex => $anchor) {
                if ($anchor === $cue) {
                    $anchors[$commentIndex] = $joined;
                }
            }
        }
        $joined->setEnd($end)->setLinesByArray($lines);

        $this->cues = array_values(array_filter(
            $this->cues,
            fn (SubtitleCue $cue): bool => $cue === $joined || !in_array($cue, $group, true)
        ));

        return $anchors;
    }


    private function getEditableCue(int $index): SubtitleCue
    {
        if (!array_key_exists($index, $this->cues)) {
            throw new InvalidArgumentException("Cannot edit cue $index - cue not found!");
        }

        return $this->cues[$index];
    }


    /**
     * Returns the cue that each comment comes before, or null for a comment after the last cue.
     *
     * @return array<int, ?SubtitleCue>
     */
    private function getCommentAnchors(): array
    {
        return array_map(fn (array $comment): ?SubtitleCue => $this->findAnchor($this->cues, $comment["beforeCueIndex"]),
                         $this->comments);
    }


    /**
     * @param SubtitleCue[] $cues
     */
    private function findAnchor(array $cues, int $beforeCueIndex): ?SubtitleCue
    {
        foreach ($cues as $index => $cue) {
            if ($index >= $beforeCueIndex) {
                return $cue;
            }
        }

        return null;
    }


    /**
     * @param list<array{text: string, beforeCueIndex: int}> $comments
     * @param array<int, ?SubtitleCue> $anchors
     */
    private function setCommentsByAnchors(array $comments, array $anchors): void
    {
        $comments = array_values($comments);
        $anchors  = array_values($anchors);
        foreach ($comments as $commentIndex => $comment) {
            $cueIndex = $anchors[$commentIndex] === null ? false : array_search($anchors[$commentIndex], $this->cues, true);

            $comments[$commentIndex]["beforeCueIndex"] = $cueIndex === false ? count($this->cues) : $cueIndex;
        }

        usort($comments, fn (array $comment1, array $comment2): int =>
            $comment1["beforeCueIndex"] <=> $comment2["beforeCueIndex"]);
        $this->comments = $comments;
    }
}
