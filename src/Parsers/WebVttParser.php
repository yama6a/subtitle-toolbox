<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WebVttParser extends SubtitleParser
{
    public function parse(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeEOLs($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeSpaces($rawSubtitle);
        $rawSubtitle = StringHelpers::removeDoubleEmptyLines($rawSubtitle);
        $rawSubtitle = StringHelpers::trimEachLine($rawSubtitle);
        $rawSubtitle = trim($rawSubtitle);  // remove empty lines on the top and bottom of the file

        if (strpos($rawSubtitle, "WEBVTT") !== 0) {
            throw new ParsingException("The file doesn't start with the string WEBVTT!");
        }

        $blocks   = explode(StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING, $rawSubtitle);
        $subtitle = new Subtitle();
        foreach ($blocks as $idx => $rawBlock) {
            $rawLines = explode(StringHelpers::UNIX_LINE_ENDING, $rawBlock);
            switch (true) {
                case $idx === 0: // skip the first block with "WEBVTT" at the beginning
                    if (count($rawLines) > 1) {
                        throw new ParsingException("No empty line found after the first line containing WEBVTT!");
                    }
                    break;
                case strpos(strtoupper($rawLines[0]), "NOTE") === 0:
                    $this->addCommentToSubtitle($subtitle, $idx, $rawLines);
                    break;
                case strpos(strtoupper($rawLines[0]), "STYLE") === 0:
                    $this->addStyleToSubtitle($subtitle, $rawLines);
                    break;
                case (strpos($rawLines[0], ' --> ') !== false) or (strpos($rawLines[1], ' --> ') !== false):
                    $subtitle->addCue($this->parseCue($rawLines, $idx));
                    break;
                default:
                    throw new ParsingException("Block #$idx doesn't match anything that we can parse as a WebVTT cue!");
            }
        }

        return $subtitle;
    }


    private function parseCue(array $rawLines, int $index): SubtitleCue
    {
        if (strpos($rawLines[1], ' --> ') !== false) {
            $cueTitle = $rawLines[0];
            $rawLines = array_slice($rawLines, 1);
        }
        if (count($rawLines) < 2) {
            throw new ParsingException("Block #$index doesn't have any text lines!");
        }
        $times = explode('-->', $rawLines[0]);
        $cue   = new SubtitleCue(
            $this->timeStringToMilliseconds($times[0]),
            $this->timeStringToMilliseconds($times[1]),
            array_slice($rawLines, 1)
        );
        if (isset($cueTitle)) {
            $this->addCueTitle($cue, $cueTitle);
        }

        return $cue;
    }


    private function timeStringToMilliseconds(string $timeString): float
    {
        $timeString = trim($timeString);
        // ignore anything after the millis (space followed by anything: (\ .*)?$ )
        // because we don't support parsing position data yet
        // ToDo: implement position data in SubtitleToolbox\SubtitleCue
        if (!preg_match("/^((\d{2,3}):)?([0-5]\d):([0-5]\d).(\d{3})(\ .*)?$/", $timeString, $matches)) {
            throw new ParsingException("The time-string of at least one cue could not be parsed: $timeString");
        }

        $hours   = intval($matches[2]);
        $minutes = intval($matches[3]);
        $seconds = intval($matches[4]);
        $millis  = intval($matches[5]);

        return $hours * 3600 + $minutes * 60 + $seconds + $millis / 1000;
    }


    /**
     * We don't support parsing the cue-title yet
     * ToDo: implement cue titles in the SubtitleToolbox\SubtitleCue class and this parser
     *
     * @param SubtitleCue $cue
     * @param string      $cueTitle
     */
    private function addCueTitle(SubtitleCue $cue, string $cueTitle)
    {
        return;
    }


    /**
     * We don't support parsing of comments yet.
     * ToDo: implement comments in the SubtitleToolbox\Subtitle class and this parser
     *
     * @param Subtitle $subtitle
     * @param int      $index
     * @param array    $rawLines
     */
    private function addCommentToSubtitle(Subtitle $subtitle, int $index, array $rawLines)
    {
        return;
    }


    /**
     * We don't support parsing of styles yet.
     * ToDo: implement styles in the SubtitleToolbox\Subtitle class and this parser
     *
     * @param Subtitle $subtitle
     * @param array    $rawLines
     */
    private function addStyleToSubtitle(Subtitle $subtitle, array $rawLines)
    {
        return;
    }
}
