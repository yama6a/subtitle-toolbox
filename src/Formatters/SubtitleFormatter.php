<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Subtitle;

abstract class SubtitleFormatter
{
    const OPTION_STRIP_ALL_XML_TAGS = "OPTION_STRIP_ALL_XML_TAGS";

    abstract function format(Subtitle $subtitle, array $options = []): string;
}
