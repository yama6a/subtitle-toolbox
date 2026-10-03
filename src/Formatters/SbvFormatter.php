<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

class SbvFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $blocks = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = Markup::plainLines($cue->getLines());

            // An empty line ends an SBV cue, so a cue without text cannot be written.
            if ($lines === []) {
                continue;
            }

            $blocks[] = sprintf("%d:%02d:%02d.%03d,%d:%02d:%02d.%03d", ...Timecode::milliseconds($cue->getStart()), ...Timecode::milliseconds($cue->getEnd())) .
                        StringHelpers::UNIX_LINE_ENDING .
                        implode(StringHelpers::UNIX_LINE_ENDING, $lines) .
                        StringHelpers::UNIX_LINE_ENDING;
        }

        return $this->applyOutputOptions(implode(StringHelpers::UNIX_LINE_ENDING, $blocks), $options);
    }
}
