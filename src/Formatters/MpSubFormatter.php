<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MpSubFormatter extends SubtitleFormatter
{
    const MPSUB_HEADER = "TITLE=" . StringHelpers::UNIX_LINE_ENDING .
                         "AUTHOR=https://github.com/ymakhloufi/subtitle-toolbox" . StringHelpers::UNIX_LINE_ENDING .
                         "TYPE=VIDEO" . StringHelpers::UNIX_LINE_ENDING .
                         "FORMAT=TIME" . StringHelpers::UNIX_LINE_ENDING .
                         "NOTE=https://github.com/ymakhloufi/subtitle-toolbox" . StringHelpers::UNIX_LINE_ENDING .
                         StringHelpers::UNIX_LINE_ENDING;


    public function format(Subtitle $subtitle): string
    {
        $output      = static::MPSUB_HEADER;
        $previousEnd = 0;
        foreach ($subtitle->getCues() as $cueIndex => $cue) {
            $output .= $this->getTimestamp($cue, $previousEnd);
            $output .= $cue->getLines()->implode(StringHelpers::UNIX_LINE_ENDING);
            $output .= StringHelpers::UNIX_LINE_ENDING;

            $previousEnd = $cue->getEnd();
        }

        return StringHelpers::addUtf8Bom($output);
    }


    private function getTimestamp(SubtitleCue $cue, float $previousEnd)
    {
        $start    = round($cue->getStart() - $previousEnd, 1);
        $duration = round($cue->getEnd() - $cue->getStart(), 1);

        return $start . " " . $duration . StringHelpers::UNIX_LINE_ENDING;
    }
}
