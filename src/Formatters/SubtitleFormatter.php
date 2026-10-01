<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Subtitle;

abstract class SubtitleFormatter
{
    public const OPTION_STRIP_ALL_XML_TAGS = "OPTION_STRIP_ALL_XML_TAGS";

    abstract public function format(Subtitle $subtitle, array $options = []): string;
}
