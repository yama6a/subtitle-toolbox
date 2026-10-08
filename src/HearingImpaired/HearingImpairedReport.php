<?php

declare(strict_types=1);

namespace SubtitleToolbox\HearingImpaired;

final class HearingImpairedReport
{
    /**
     * @internal
     *
     * @param int $removedLines the number of removed lines, the lines of removed cues included
     * @param int $removedCues  the number of cues that had text and have none left
     */
    public function __construct(
        public readonly int $removedLines,
        public readonly int $removedCues,
    ) {
    }
}
