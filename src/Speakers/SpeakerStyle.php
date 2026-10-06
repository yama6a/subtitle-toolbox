<?php

declare(strict_types=1);

namespace SubtitleToolbox\Speakers;

enum SpeakerStyle: string
{
    /** The speaker name and a separator before the first line of the speaker, as in "JOHN: Hi." */
    case Prefix = "prefix";

    /** A dialogue dash before the first line of each speaker, in cues with two or more speakers. */
    case DialogueDashes = "dialogueDashes";

    /** A <font color> tag around each line, one color per speaker. */
    case Colors = "colors";
}
