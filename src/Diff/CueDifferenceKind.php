<?php

declare(strict_types=1);

namespace SubtitleToolbox\Diff;

enum CueDifferenceKind: string
{
    case Added                = "added";
    case Removed              = "removed";
    case TextChanged          = "text changed";
    case TimingChanged        = "timing changed";
    case TextAndTimingChanged = "text and timing changed";
}
