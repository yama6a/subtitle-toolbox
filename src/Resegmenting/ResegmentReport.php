<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

final class ResegmentReport
{
    /** @internal */
    public function __construct(
        public readonly int $cuesBefore,
        public readonly int $cuesAfter,
    ) {
    }
}
