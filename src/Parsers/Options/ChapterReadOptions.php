<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

use SubtitleToolbox\OptionChecks;

/**
 * The read settings of the chapter formats: YouTube, Podcasting 2.0, FFmetadata and OGM chapters.
 */
final class ChapterReadOptions implements FormatReadOptions
{
    /**
     * @param ?float $mediaDuration Seconds where a last chapter without its own end ends. An FFmpeg END or a Podcasting 2.0
     *                              endTime wins. A last chapter that starts after it, or null, ends at its own start.
     */
    public function __construct(public readonly ?float $mediaDuration = null)
    {
        if ($mediaDuration !== null) {
            OptionChecks::nonNegativeFinite($mediaDuration, "The media duration must be 0 or more seconds, got %s.");
        }
    }
}
