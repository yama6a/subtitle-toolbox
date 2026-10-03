<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class HearingImpairedReport
{
    /**
     * Holds the number of lines that the removal dropped, the lines of removed cues included, and of cues it removed.
     */
    public function __construct(
        public readonly int $removedLines,
        public readonly int $removedCues,
    ) {
    }
}
