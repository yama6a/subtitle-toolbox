<?php

declare(strict_types=1);

namespace SubtitleToolbox\Karaoke;

enum WordHighlightMode: string
{
    /** Styles only the active word. */
    case Word = "word";

    /** Styles every word up to the active one. */
    case Cumulative = "cumulative";
}
