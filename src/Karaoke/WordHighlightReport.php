<?php

declare(strict_types=1);

namespace SubtitleToolbox\Karaoke;

final class WordHighlightReport
{
    public function __construct(
        public readonly int $cuesBefore,
        public readonly int $cuesAfter,
    ) {
    }
}
