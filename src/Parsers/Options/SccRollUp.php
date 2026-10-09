<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers\Options;

/**
 * How the SCC parser turns roll-up captions into cues.
 */
enum SccRollUp: string
{
    /** One cue per screen. A row shows in each cue until it rolls off. */
    case Screen = "screen";

    /** One cue per row. A row starts at its first character and ends when it rolls up or the screen clears. */
    case Lines = "lines";
}
