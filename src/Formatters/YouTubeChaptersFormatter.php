<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;

class YouTubeChaptersFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $output = "";
        foreach ($subtitle->getCues() as $cue) {
            $output .= rtrim($this->formatTime($cue->getStart()) . " " . implode(" ", Markup::plainLines($cue->getLines()))) . "\n";
        }

        return $this->applyOutputOptions($output, $options);
    }


    private function formatTime(float $seconds): string
    {
        $seconds = (int) floor($seconds);

        return $seconds < 3600
            ? sprintf("%d:%02d", intdiv($seconds, 60), $seconds % 60)
            : sprintf("%d:%02d:%02d", intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
    }
}
