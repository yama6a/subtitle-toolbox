<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

final class SpeakerLabelReport
{
    /**
     * @internal SpeakerLabels::apply() creates the report.
     */
    public function __construct(
        public readonly int $changedCues,
    ) {
    }
}
