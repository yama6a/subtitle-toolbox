<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class LyricsFormatter extends SubtitleFormatter
{
    function format(Subtitle $subtitle, array $options = []): string
    {
        $output = "";
        foreach ($subtitle->getCues() as $cueIndex => $cue) {
            $output .= $this->formatCue($cue);
            $output .= StringHelpers::UNIX_LINE_ENDING;
        }

        return StringHelpers::addUtf8Bom($output);
    }


    private function formatCue(SubtitleCue $cue)
    {
        $timestamp = $this->formatTimeToString($cue->getStart());

        // strip all xml markup
        // ToDo: make this more sophisticated to support word-timing (e.g. [00:11.22] Foo <00:12.50>Bar <00:13.80>Baz)
        $lines = strip_tags(implode(" ", $cue->getLines()));

        return $timestamp . " " . $lines;
    }


    private function formatTimeToString(float $timeInSeconds)
    {
        $minute       = str_pad(floor($timeInSeconds / 60), 2, "0", STR_PAD_LEFT);
        $second       = str_pad(floor($timeInSeconds) % 60, 2, "0", STR_PAD_LEFT);
        $centiseconds = str_pad(round(($timeInSeconds - floor($timeInSeconds)) * 100), 2, "0", STR_PAD_LEFT);

        return "[" . $minute . ":" . $second . "." . $centiseconds . "]";
    }
}
