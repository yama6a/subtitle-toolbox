<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Markup;
use SubtitleToolbox\Options;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

class SubRipFormatter extends SubtitleFormatter
{
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $output = "";
        foreach (array_values($subtitle->getCues()) as $cueIndex => $cue) {
            $output .= $this->formatNumberedCue($cue, $cueIndex, $options);
        }

        return $this->applyOutputOptions(StringHelpers::addUtf8Bom($output), $options);
    }


    /**
     * Returns what format() writes for the cue at $cueIndex, in the line ending of $options, without a BOM.
     */
    public function formatCueBlock(SubtitleCue $cue, int $cueIndex, array $options = []): string
    {
        $block = $this->formatNumberedCue($cue, $cueIndex, $options);

        return $this->applyOutputOptions($block, [...$options, parent::OPTION_BOM => null]);
    }


    private function formatNumberedCue(SubtitleCue $cue, int $cueIndex, array $options): string
    {
        $output = "";
        if ($cueIndex > 0) {
            $output .= StringHelpers::UNIX_LINE_ENDING;
        }
        $output .= $cueIndex + 1 . StringHelpers::UNIX_LINE_ENDING;
        $output .= $this->formatCue($cue, $options);
        $output .= StringHelpers::UNIX_LINE_ENDING;

        return $output;
    }


    private function formatCue(SubtitleCue $cue, array $options): string
    {
        $time  = sprintf("%02d:%02d:%02d,%03d --> %02d:%02d:%02d,%03d", ...Timecode::milliseconds($cue->getStart()), ...Timecode::milliseconds($cue->getEnd()));
        $time .= $this->formatCoordinates($cue);
        $lines = implode(StringHelpers::UNIX_LINE_ENDING, $cue->getLines());

        // strip xml tags depending on option settings
        $lines = (bool) (Options::flag($options, parent::OPTION_STRIP_ALL_XML_TAGS) ?? false)
            ? Markup::stripAllTags($lines)
            : Markup::keepTags($lines, ["b", "u", "i", "s", "font"]);
        $lines = Markup::decodeEntities($lines);

        // A line that holds only a tag becomes empty, and an empty line ends the cue in SubRip.
        $lines = explode(StringHelpers::UNIX_LINE_ENDING, $lines);
        $lines = implode(StringHelpers::UNIX_LINE_ENDING, array_filter($lines, fn(string $line) => trim($line) !== ""));

        if ($cue->getAlignment() !== null && $cue->getAlignment() !== 2) {
            $lines = "{\\an{$cue->getAlignment()}}" . $lines;
        }


        return $time . StringHelpers::UNIX_LINE_ENDING . $lines;
    }


    private function formatCoordinates(SubtitleCue $cue): string
    {
        $coordinates = $cue->getFormatData("srt")["coordinates"] ?? null;
        if (!is_array($coordinates)) {
            return "";
        }

        return " X1:{$coordinates["x1"]} X2:{$coordinates["x2"]} Y1:{$coordinates["y1"]} Y2:{$coordinates["y2"]}";
    }
}
