<?php

declare(strict_types=1);

namespace SubtitleToolbox\Resegmenting;

enum ResegmentMode: string
{
    /** Splits each cue that breaks a limit, at sentence ends, then at clause ends, then at the space closest to the middle. */
    case SplitLong = "splitLong";

    /** Drops the cue boundaries and builds new cues from the word timestamps, one sentence per cue within the limits. */
    case ByWords = "byWords";
}
