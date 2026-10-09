<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

final class SccWriteReport
{
    /**
     * @internal
     *
     * @param string             $content the SCC output, as SccFormatter::format() returns it
     * @param list<SccFitChange> $changes each change of SccWriteOptions::$fit in the order of the cues. Empty without $fit.
     */
    public function __construct(
        public readonly string $content,
        public readonly array $changes,
    ) {
    }
}
