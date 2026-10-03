<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;


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
}
