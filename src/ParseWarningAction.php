<?php

declare(strict_types=1);

namespace SubtitleToolbox;

enum ParseWarningAction: string
{
    /** The parser dropped the block. */
    case Skipped = "skipped";

    /** The parser kept the block and fixed the problem. */
    case Repaired = "repaired";
}
