<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\ParseWarning;
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


    /**
     * Returns the warnings of the current or last read() so far. Only a reader with ReadOptions::$lenient has warnings.
     *
     * @return list<ParseWarning>
     */
    public function getWarnings(): array;
}
