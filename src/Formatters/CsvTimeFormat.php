<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

/**
 * A time column format of CSV and TSV files. The value is the pattern, such as "hh:mm:ss,mmm".
 */
enum CsvTimeFormat: string
{
    case Seconds = "seconds";
    case Dot     = "hh:mm:ss.mmm";
    case Comma   = "hh:mm:ss,mmm";
    case Frames  = "hh:mm:ss:ff";
}
