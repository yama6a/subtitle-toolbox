<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

trait Fixes
{
    /**
     * Moves the end of each cue to at least $minGap seconds before the start of the next cue, but not before its own start.
     */
    public function fixOverlaps(float $minGap = 0): self
    {
        $this->fixesAssertGap($minGap);

        $cues = CueList::inStartOrder($this->cues);
        foreach ($cues as $index => $cue) {
            if (!isset($cues[$index + 1])) {
                continue;
            }

            $latestEnd = round($cues[$index + 1]->getStart() - $minGap, 3);
            if ($cue->getEnd() > $latestEnd) {
                $cue->setEnd(max($cue->getStart(), $latestEnd));
            }
        }

        return $this;
    }


    /**
     * Makes each cue last at least $minDuration seconds, but ends it at least $minGap seconds before the next cue starts.
     */
    public function extendShortCues(float $minDuration, float $minGap = 0): self
    {
        if ($minDuration <= 0) {
            throw new InvalidArgumentException("The minimum duration must be greater than 0, got $minDuration.");
        }
        $this->fixesAssertGap($minGap);

        $cues = CueList::inStartOrder($this->cues);
        foreach ($cues as $index => $cue) {
            $end = $cue->getStart() + $minDuration;
            if (isset($cues[$index + 1])) {
                $end = min($end, $cues[$index + 1]->getStart() - $minGap);
            }

            $hasSameStartAsPrevious = isset($cues[$index - 1]) && $cues[$index - 1]->getStart() === $cue->getStart();
            if (!$hasSameStartAsPrevious && round($end, 3) > $cue->getEnd()) {
                $cue->setEnd($end);
            }
        }

        return $this;
    }


    /**
     * Breaks the lines of each cue that has a line longer than $maxCharsPerLine or more than $maxLines lines.
     */
    public function wrapLines(int $maxCharsPerLine, int $maxLines = 2): self
    {
        if ($maxCharsPerLine < 1 || $maxLines < 1) {
            throw new InvalidArgumentException("The maximum characters per line and the maximum lines must be at " .
                                               "least 1, got $maxCharsPerLine and $maxLines.");
        }

        foreach ($this->getCues() as $cue) {
            if (!LineWrapper::fits($cue->getLines(), $maxCharsPerLine, $maxLines)) {
                $cue->setLinesByArray(LineWrapper::wrap($cue->getLines(), $maxCharsPerLine, $maxLines));
            }
        }

        return $this;
    }


    /**
     * Joins all lines of each cue into one line, with a space between them.
     */
    public function unwrapLines(): self
    {
        foreach ($this->getCues() as $cue) {
            $cue->setLinesByArray([implode(" ", $cue->getLines())]);
        }

        return $this;
    }


    private function fixesAssertGap(float $minGap): void
    {
        if ($minGap < 0) {
            throw new InvalidArgumentException("The minimum gap must not be negative, got $minGap.");
        }
    }
}
