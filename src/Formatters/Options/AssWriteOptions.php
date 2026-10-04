<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class AssWriteOptions implements FormatWriteOptions
{
    public function __construct(
        public readonly AssKaraokeTag $karaokeTag = AssKaraokeTag::Instant,   // the override tag of a karaoke syllable
    ) {
    }
}
