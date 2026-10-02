<?php

namespace SubtitleToolbox\Fixing;

final class AppliedFix
{
    /**
     * Records that $rule changed the text of the cue at $cueIndex from $before to $after, with lines joined by "\n".
     */
    public function __construct(
        public readonly int $cueIndex,
        public readonly string $rule,
        public readonly string $before,
        public readonly string $after,
    ) {
    }
}
