<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class SbvFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, ?WriteOptions $options = null): string
    {
        $options ??= new WriteOptions();
        $blocks = [];
        foreach ($subtitle->getCues() as $cue) {
            $lines = Markup::plainLines($cue->getLines());

            // An empty line ends an SBV cue, so a cue without text cannot be written.
            if ($lines === []) {
                continue;
            }

            $blocks[] = sprintf("%d:%02d:%02d.%03d,%d:%02d:%02d.%03d", ...Timecode::milliseconds($cue->getStart()), ...Timecode::milliseconds($cue->getEnd())) .
                        LineEnding::Lf->value .
                        implode(LineEnding::Lf->value, $lines) .
                        LineEnding::Lf->value;
        }

        return $this->applyOutputOptions(implode(LineEnding::Lf->value, $blocks), $options);
    }
}
