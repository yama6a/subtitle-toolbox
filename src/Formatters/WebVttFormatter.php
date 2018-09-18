<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WebVttFormatter extends SubtitleFormatter
{
    function format(Subtitle $subtitle): string
    {
        $output = "WEBVTT" . StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING;
        foreach ($subtitle->getCues() as $cueIndex => $cue) {
            if ($cueIndex > 0) {
                $output .= StringHelpers::UNIX_LINE_ENDING;
            }
            $output .= $cueIndex + 1 . StringHelpers::UNIX_LINE_ENDING;
            $output .= $this->formatCue($cue);
            $output .= StringHelpers::UNIX_LINE_ENDING;
        }

        return StringHelpers::addUtf8Bom($output);
    }


    private function formatCue(SubtitleCue $cue)
    {
        $timeStamps = $this->formatTimeToString($cue->getStart()) . " --> " . $this->formatTimeToString($cue->getEnd());

        // strip all xml markup except SRT-supported formatting tags
        //ToDo: add all allowed tags to the exceptions in strip_tags()
        $lines = strip_tags($cue->getLines()->implode(StringHelpers::UNIX_LINE_ENDING), "<b><u><i><font>");

        return $timeStamps . StringHelpers::UNIX_LINE_ENDING . $lines;
    }


    private function formatTimeToString(float $timeInSeconds)
    {
        $hour   = str_pad(floor($timeInSeconds / 3600), 2, "0", STR_PAD_LEFT);
        $minute = str_pad(floor($timeInSeconds / 60) % 60, 2, "0", STR_PAD_LEFT);
        $second = str_pad(floor($timeInSeconds) % 60, 2, "0", STR_PAD_LEFT);
        $millis = str_pad(round(($timeInSeconds - floor($timeInSeconds)) * 1000), 3, "0", STR_PAD_LEFT);

        return "$hour:$minute:$second,$millis";
    }
}
