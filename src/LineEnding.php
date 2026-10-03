<?php

declare(strict_types=1);

namespace SubtitleToolbox;

enum LineEnding: string
{
    case Lf   = "\n";
    case Crlf = "\r\n";
}
