<?php

declare(strict_types=1);

namespace SubtitleToolbox\Profanity;

final class ProfanityReport
{
    /**
     * @internal ProfanityFilter::apply() creates the report.
     *
     * @param list<MuteRange> $muteRanges the time ranges of the matches, sorted and joined
     */
    public function __construct(
        public readonly array $muteRanges,
    ) {
    }
}
