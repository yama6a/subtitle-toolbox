<?php

declare(strict_types=1);

namespace SubtitleToolbox\Fixing;

final class AppliedFix
{
    /**
     * Records that $rule changed the text of the cue at $cueIndex from $before to $after, with lines joined by "\n".
     *
     * @internal
     */
    public function __construct(
        public readonly int $cueIndex,
        public readonly CommonErrorRule $rule,
        public readonly string $before,
        public readonly string $after,
    ) {
    }
}
