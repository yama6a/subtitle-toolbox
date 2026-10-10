<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\AssStyleOverride;

final class AssWriteOptions implements FormatWriteOptions
{
    /**
     * @param AssKaraokeTag $karaokeTag The override tag of a karaoke syllable.
     * @param ?string       $style      Comma-separated Field=Value pairs that change the Default style, as FFmpeg force_style.
     *                                  For example "Fontname=Roboto,Fontsize=48". Null keeps the style.
     * @throws InvalidArgumentException for a pair without "=" or a field that the [V4+ Styles] Format line does not name.
     */
    public function __construct(
        public readonly AssKaraokeTag $karaokeTag = AssKaraokeTag::Instant,
        public readonly ?string $style = null,
    ) {
        if ($style !== null) {
            AssStyleOverride::parse($style);
        }
    }
}
