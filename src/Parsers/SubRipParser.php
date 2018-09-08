<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SubRipParser extends SubtitleParser
{
    public function parse(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeEOLs($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeSpaces($rawSubtitle);
        $rawSubtitle = StringHelpers::removeDoubleEmptyLines($rawSubtitle);
        $rawSubtitle = StringHelpers::trimEachLine($rawSubtitle);
        $rawSubtitle = trim($rawSubtitle);  // remove empty lines on the top and bottom of the file

        $rawCues  = explode(StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING, $rawSubtitle);
        $subtitle = new Subtitle();
        $i        = 0;
        foreach ($rawCues as $rawCue) {
            $i++;
            $rawLines = explode(StringHelpers::UNIX_LINE_ENDING, $rawCue);

            if (!is_numeric($rawLines[0])) {
                throw new ParsingException("Block #$i doesn't seem to have a cue-number on its first line!");
            }

            if (strpos($rawLines[1], ' --> ') === false) {
                throw new ParsingException("Block #$i doesn't seem to have its timestamps on its second line!");
            }

            if (count($rawLines) < 3) {
                throw new ParsingException("Block #$i doesn't have any text lines!");
            }

            $times = explode('-->', $rawLines[1]);
            $subtitle->addCue(new SubtitleCue(
                $this->millisFromString($times[0]),
                $this->millisFromString($times[1]),
                array_slice($rawLines, 2)
            ));
        }

        return $subtitle;
    }


    private function millisFromString(string $timeString): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^(\d{2,3}):([0-5]\d):([0-5]\d),(\d{3})$/", $timeString, $matches)) {
            throw new ParsingException("The timeString-string of at least one cue could not be parsed: $timeString");
        }

        $hours   = intval($matches[1]);
        $minutes = intval($matches[2]);
        $seconds = intval($matches[3]);
        $millis  = intval($matches[4]);

        return $hours * 3600 + $minutes * 60 + $seconds + $millis / 1000;
    }
}
