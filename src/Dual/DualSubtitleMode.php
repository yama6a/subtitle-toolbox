<?php

declare(strict_types=1);

namespace SubtitleToolbox\Dual;

enum DualSubtitleMode: string
{
    /** Writes the secondary lines below the primary lines of the cue they overlap. */
    case Stack = "stack";

    /** Keeps the secondary cues as cues of their own at the secondary alignment, at the top by default. */
    case TopBottom = "topBottom";
}
