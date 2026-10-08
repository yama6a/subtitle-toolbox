<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

final class AssWriteOptions implements FormatWriteOptions
{
    /**
     * @param AssKaraokeTag $karaokeTag The override tag of a karaoke syllable.
     */
    public function __construct(
        public readonly AssKaraokeTag $karaokeTag = AssKaraokeTag::Instant,
    ) {
    }
}
