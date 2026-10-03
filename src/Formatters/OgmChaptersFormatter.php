<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;

class OgmChaptersFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $output = "";
        foreach (array_values($subtitle->getCues()) as $index => $cue) {
            $number  = sprintf("CHAPTER%02d", $index + 1);
            $output .= "$number=" . Markup::coreTimestamp($cue->getStart()) . "\n" .
                       "{$number}NAME=" . implode(" ", Markup::plainLines($cue->getLines())) . "\n";
        }

        return $this->applyOutputOptions($output, $options);
    }
}
