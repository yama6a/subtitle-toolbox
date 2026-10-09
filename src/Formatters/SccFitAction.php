<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

/**
 * What SccWriteOptions::$fit changed in a cue.
 */
enum SccFitAction: string
{
    /** Characters that CEA-608 lacks became similar characters, for example "Š" became "S". */
    case Transliterated = "transliterated";

    /** The text wrapped again into at most 4 lines of at most 32 characters. */
    case Wrapped = "wrapped";

    /** The caption shows later than the cue start, because the frames before it cannot hold its data. */
    case Delayed = "delayed";

    /** The caption is not in the output, because its data cannot be sent before the cue end. */
    case Dropped = "dropped";
}
