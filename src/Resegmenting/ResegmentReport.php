<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

final class ResegmentReport
{
    /**
     * @internal Resegmenter::apply() creates the report.
     */
    public function __construct(
        public readonly int $cuesBefore,
        public readonly int $cuesAfter,
    ) {
    }
}
