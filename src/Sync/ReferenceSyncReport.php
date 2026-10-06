<?php

declare(strict_types=1);

namespace SubtitleToolbox\Sync;

final class ReferenceSyncReport
{
    /**
     * @internal ReferenceSync::apply() creates the report.
     *
     * @param float                                   $offset     the seconds to add to each time after the scale, in the first part when the sync split the subtitle
     * @param float                                   $scale      the factor to multiply each time with before the offset
     * @param float                                   $score      the cue time that both files share after the sync, divided by the cue time of either file, from 0 to 1
     * @param list<array{from: float, offset: float}> $splitParts
     */
    public function __construct(
        public readonly float $offset,
        public readonly float $scale,
        public readonly float $score,
        private readonly array $splitParts = [],
    ) {
    }


    /**
     * Returns the parts of the subtitle, each with its range of subtitle cue start times, the scale and the offset.
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
