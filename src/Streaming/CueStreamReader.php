<?php

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\SubtitleCue;

interface CueStreamReader
{
    /**
     * Yields the cues of a stream resource or file path in file order, one cue block at a time.
     *
     * @param resource|string $stream
     *
     * @return Generator<int, SubtitleCue>
     */
    public function read($stream): Generator;
}
