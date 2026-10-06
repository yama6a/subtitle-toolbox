<?php

declare(strict_types=1);

namespace SubtitleToolbox\Diff;

use SubtitleToolbox\SubtitleCue;

final class CueDifference
{
    /**
     * @internal SubtitleDiff::compare() creates the differences.
     *
     * @param ?int $oldIndex the cue index in the old subtitle, or null for an added cue
     * @param ?int $newIndex the cue index in the new subtitle, or null for a removed cue
     */
    public function __construct(
        public readonly CueDifferenceKind $kind,
        public readonly ?int $oldIndex,
        public readonly ?int $newIndex,
        public readonly ?SubtitleCue $oldCue,
        public readonly ?SubtitleCue $newCue,
    ) {
    }
}
