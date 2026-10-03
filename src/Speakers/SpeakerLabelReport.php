<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

final class SpeakerLabelReport
{
    public function __construct(
        public readonly int $changedCues,
    ) {
    }
}
