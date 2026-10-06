<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

/**
 * The ASS override tag that marks the time of a karaoke syllable. The value is the tag name without the backslash.
 */
enum AssKaraokeTag: string
{
    case Instant = "k";      // highlights the whole syllable when its time starts
    case Fill    = "kf";     // fills the syllable from left to right
    case Outline = "ko";     // hides the outline of the syllable until its time starts
}
