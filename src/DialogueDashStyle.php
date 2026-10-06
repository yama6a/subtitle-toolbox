<?php

declare(strict_types=1);

namespace SubtitleToolbox;

/**
 * The dash at the start of a dialogue line and the space after it.
 */
enum DialogueDashStyle: string
{
    case Hyphen             = "-";
    case HyphenSpace        = "- ";
    case UnicodeHyphen      = "\u{2010}";
    case UnicodeHyphenSpace = "\u{2010} ";
    case EnDash             = "\u{2013}";
    case EnDashSpace        = "\u{2013} ";
    case EmDash             = "\u{2014}";
    case EmDashSpace        = "\u{2014} ";
}
