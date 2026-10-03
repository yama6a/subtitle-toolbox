<?php

declare(strict_types=1);

namespace SubtitleToolbox;

final class ResegmentReport
{
    public function __construct(
        public readonly int $cuesBefore,
        public readonly int $cuesAfter,
    ) {
    }
}
