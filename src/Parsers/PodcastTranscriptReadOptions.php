<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * The read settings of Podcasting 2.0 JSON transcripts.
 */
final class PodcastTranscriptReadOptions implements FormatReadOptions
{
    /**
     * @param bool $keepSegments Make one cue per segment instead of joining single-word segments into cues.
     */
    public function __construct(public readonly bool $keepSegments = false)
    {
    }
}
