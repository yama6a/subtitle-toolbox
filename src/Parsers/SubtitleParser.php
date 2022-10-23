<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Subtitle;

abstract class SubtitleParser
{
    abstract public function parse(string $rawSubtitle): Subtitle;
}
