<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

/**
 * The ASS override tag that marks the time of a karaoke syllable. The value is the tag name without the backslash.
 */
enum AssKaraokeTag: string
{
    /** Highlights the whole syllable when its time starts. */
    case Instant = "k";

    /** Fills the syllable from left to right. */
    case Fill = "kf";

    /** Hides the outline of the syllable until its time starts. */
    case Outline = "ko";
}
