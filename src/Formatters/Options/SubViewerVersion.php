<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters\Options;

/**
 * The SubViewer version that SubViewerFormatter writes.
 */
enum SubViewerVersion: int
{
    case V1 = 1;
    case V2 = 2;
}
