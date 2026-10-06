<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

final class YouTubeChaptersFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $output = "";
        foreach ($subtitle->getCues() as $cue) {
            $start   = Timecode::shortClock($cue->getStart());
            $output .= rtrim($start . " " . implode(" ", Markup::plainLines($cue->getLines()))) . "\n";
        }

        return $this->applyOutputOptions($output, $options);
    }
}
