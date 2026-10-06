<?php

declare(strict_types=1);

namespace SubtitleToolbox;

enum CaseMode: string
{
    case Upper    = "upper";
    case Lower    = "lower";

    /** Lower case with an upper case letter at the start of each cue and after ., ! and ?. */
    case Sentence = "sentence";
}
