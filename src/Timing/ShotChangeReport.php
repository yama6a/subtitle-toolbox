<?php

declare(strict_types=1);

namespace SubtitleToolbox\Timing;

final class ShotChangeReport
{
    /**
     * Holds the number of cue starts and of cue ends that moved by one frame or more.
     *
     * @internal
     */
    public function __construct(
        public readonly int $movedStarts,
        public readonly int $movedEnds,
    ) {
    }
}
