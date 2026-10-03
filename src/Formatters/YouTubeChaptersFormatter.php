<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;

class YouTubeChaptersFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $output = "";
        foreach ($subtitle->getCues() as $cue) {
            [$hours, $minutes, $seconds] = Timecode::seconds(floor($cue->getStart()));
            $start   = $hours > 0 ? sprintf("%d:%02d:%02d", $hours, $minutes, $seconds) : sprintf("%d:%02d", $minutes, $seconds);
            $output .= rtrim($start . " " . implode(" ", Markup::plainLines($cue->getLines()))) . "\n";
        }

        return $this->applyOutputOptions($output, $options);
    }
}
