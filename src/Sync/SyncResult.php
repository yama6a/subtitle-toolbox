<?php

namespace SubtitleToolbox\Sync;

use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class SyncResult
{
    /**
     * @param list<array{from: float, offset: float}> $splitParts
     */
    public function __construct(
        private readonly float $offset,
        private readonly float $scale,
        private readonly float $score,
        private readonly array $splitParts = [],
    ) {
    }


    /**
     * Returns the seconds to add to each time after the scale, in the first part when the result has splits.
     */
    public function getOffset(): float
    {
        return $this->offset;
    }


    /**
     * Returns the factor to multiply each time with before the offset.
     */
    public function getScale(): float
    {
        return $this->scale;
    }


    /**
     * Returns the cue time that both files share after the sync, divided by the cue time of either file, from 0 to 1.
     */
    public function getScore(): float
    {
        return $this->score;
    }


    /**
     * Returns the parts of the target, each with its range of target cue start times, the scale and the offset.
     *
     * @return list<array{from: float, to: float, scale: float, offset: float}>
     */
    public function getSegments(): array
    {
        $parts    = $this->splitParts === [] ? [["from" => 0.0, "offset" => $this->offset]] : $this->splitParts;
        $segments = [];
        foreach ($parts as $index => $part) {
            $segments[] = [
                "from"   => $part["from"],
                "to"     => $parts[$index + 1]["from"] ?? INF,
                "scale"  => $this->scale,
                "offset" => $part["offset"],
            ];
        }

        return $segments;
    }


    /**
     * Scales and then shifts the cues of $target, each cue with the segment that holds its start, and returns $target.
     */
    public function apply(Subtitle $target): Subtitle
    {
        if (count($this->splitParts) > 1) {
            return $this->applySegments($target);
        }

        if ($this->scale != 1) {
            $target->scale($this->scale);
        }

        if ($this->offset != 0) {
            $target->shift($this->offset);
        }

        return $target;
    }


    private function applySegments(Subtitle $target): Subtitle
    {
        $segments = $this->getSegments();
        $parts    = array_fill(0, count($segments), []);
        foreach ($target->getCues() as $cue) {
            $index = count($segments) - 1;
            while ($index > 0 && $cue->getStart() < $segments[$index]["from"]) {
                $index--;
            }
            $parts[$index][] = $cue;
        }

        foreach ($parts as $index => $cues) {
            foreach ($cues as $cue) {
                $cue->setStart(max(0, $cue->getStart() * $this->scale + $segments[$index]["offset"]))
                    ->setEnd(max(0, $cue->getEnd() * $this->scale + $segments[$index]["offset"]));
            }
        }

        for ($index = 1; $index < count($parts); $index++) {
            if ($parts[$index] === []) {
                continue;
            }

            $laterStart = min(array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $parts[$index]));
            for ($earlier = 0; $earlier < $index; $earlier++) {
                foreach ($parts[$earlier] as $cue) {
                    if ($cue->getStart() < $laterStart && $cue->getEnd() > $laterStart - 0.001) {
                        $cue->setEnd(max($cue->getStart(), $laterStart - 0.001));
                    }
                }
            }
        }

        return $target->reIndexCues();
    }
}
