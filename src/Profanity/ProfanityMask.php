<?php

declare(strict_types=1);

namespace SubtitleToolbox\Profanity;

enum ProfanityMask: string
{
    /** One star per character: "hell" becomes "****". */
    case Stars = "stars";

    /** The first letter and one star per other character: "hell" becomes "h***". */
    case FirstLetter = "firstLetter";

    /** Removes the word. */
    case Remove = "remove";

    /** Keeps the word. Only the report holds the mute ranges. */
    case None = "none";
}
