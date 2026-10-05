<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

/**
 * The read settings of the transcript formats: Whisper, the cloud speech JSON formats, YouTube timed text and
 * Podcasting 2.0 transcripts.
 */
final class TranscriptReadOptions implements FormatReadOptions
{
    /**
     * @param bool $wordTimestamps Write word times as core markup.
     * @param bool $speakerVoices  Write speakers as voice tags: Whisper and cloud speech JSON. Podcasting 2.0 always writes them.
     * @param bool $keepSegments   Podcasting 2.0 only: make one cue per segment instead of joining single-word segments into cues.
     */
    public function __construct(
        public readonly bool $wordTimestamps = false,
        public readonly bool $speakerVoices = false,
        public readonly bool $keepSegments = false,
    ) {
    }
}
