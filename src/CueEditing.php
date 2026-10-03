<?php

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

trait CueEditing
{
    /**
     * Appends copies of the cues of $other, moved by $offset seconds. Metadata and format data of $this win.
     */
    public function merge(Subtitle $other, float $offset = 0): self
    {
        $ownAnchors   = CommentAnchors::of($this->cues, $this->comments);
        $otherCues    = [];
        $otherAnchors = [];
        foreach ($other->getCues() as $index => $cue) {
            $otherCues[$index] = (clone $cue)
                ->setStart(max(0, $cue->getStart() + $offset))
                ->setEnd(max(0, $cue->getEnd() + $offset));
        }
        foreach ($other->getComments() as $comment) {
            $otherAnchors[] = CommentAnchors::anchor($otherCues, $comment["beforeCueIndex"]);
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
        $this->comments = CommentAnchors::comments($this->cues, $comments, array_merge($ownAnchors, $otherAnchors));

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

        $copies = new \SplObjectStorage();
        foreach ($this->cues as $cue) {
            if ($cue->getEnd() <= $from || $cue->getStart() >= $to) {
                continue;
            }

            $copy = (clone $cue)
                ->setStart(max($cue->getStart(), $from) - ($moveToZero ? $from : 0))
                ->setEnd(min($cue->getEnd(), $to) - ($moveToZero ? $from : 0));

            $copies[$cue] = $copy;
        }

        return $this->copyWithCues($copies);
    }


    /**
     * Returns a copy with copies of the forced cues and the comments before these cues.
     */
    public function onlyForced(): self
    {
        $copies = new \SplObjectStorage();
        foreach ($this->cues as $cue) {
            if ($cue->isForced()) {
                $copies[$cue] = clone $cue;
            }
        }

        return $this->copyWithCues($copies);
    }


    /**
     * Returns a copy that holds the cue copies in $copies, in the order of their originals.
     *
     * @param \SplObjectStorage<SubtitleCue, SubtitleCue> $copies original cue => copy
     */
    private function copyWithCues(\SplObjectStorage $copies): self
    {
        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $copy    = clone $this;
        $cues    = [];
        foreach ($this->cues as $cue) {
            if (isset($copies[$cue])) {
                $cues[] = $copies[$cue];
            }
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

        $copy->cues = $cues;
        $copy->comments = CommentAnchors::comments($copy->cues, $comments, $newAnchors);

        return $copy;
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

        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $second  = (clone $cue)
            ->setIdentifier(null)
            ->setStart($at)
            ->setLinesByArray(array_slice($lines, $splitAfterLine));
        $cue->setEnd($at)->setLinesByArray(array_slice($lines, 0, $splitAfterLine));

        $cues     = array_values($this->cues);
        $position = array_search($cue, $cues, true);
        array_splice($cues, $position + 1, 0, [$second]);

        $this->cues = $cues;
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

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

        $anchors = CommentAnchors::of($this->cues, $this->comments);
        $group   = array_filter(
            $this->cues,
            fn (int $index): bool => $index >= $first && $index <= $last,
            ARRAY_FILTER_USE_KEY
        );

        [$this->cues, $anchors] = CueList::join($this->cues, $group, $anchors, true);
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    /**
     * Joins each run of adjacent cues with the same text where one cue ends at the start time of the next.
     */
    public function removeDuplicateCues(): self
    {
        $anchors = CommentAnchors::of($this->cues, $this->comments);
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
                [$this->cues, $anchors] = CueList::join($this->cues, $group, $anchors, false);
            }
        }
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    /**
     * Sets the cue list to $cues. Each comment moves to the cue that $anchors holds at its position, or after the last
     * cue for null. CommentAnchors::of() returns the anchors.
     *
     * @internal For the services that change the cue list, such as Resegmenter.
     *
     * @param SubtitleCue[]            $cues
     * @param array<int, ?SubtitleCue> $anchors
     */
    public function replaceCues(array $cues, array $anchors): self
    {
        $this->cues     = array_values($cues);
        $this->comments = CommentAnchors::comments($this->cues, $this->comments, $anchors);

        return $this;
    }


    private function getEditableCue(int $index): SubtitleCue
    {
        if (!array_key_exists($index, $this->cues)) {
            throw new InvalidArgumentException("Cannot edit cue $index - cue not found!");
        }

        return $this->cues[$index];
    }
}
