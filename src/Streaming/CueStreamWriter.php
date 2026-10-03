<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use SubtitleToolbox\SubtitleCue;

interface CueStreamWriter
{
    /**
     * Writes one cue to the stream.
     */
    public function write(SubtitleCue $cue): void;


    /**
     * Flushes the stream, and closes it when the writer opened it from a file path.
     */
    public function close(): void;
}
