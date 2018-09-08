<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Subtitle;

abstract class SubtitleFormatter
{
    abstract function format(Subtitle $subtitle): string;
}
