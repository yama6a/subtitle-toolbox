<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SbvParser extends SubtitleParser
{
    public function parse(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeEOLs($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeSpaces($rawSubtitle);
        $rawSubtitle = StringHelpers::trimEachLine($rawSubtitle);
        $rawSubtitle = StringHelpers::removeDoubleEmptyLines($rawSubtitle);
        $rawSubtitle = trim($rawSubtitle);

        $rawCues  = explode(StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING, $rawSubtitle);
        $subtitle = new Subtitle();
        foreach ($rawCues as $idx => $rawCue) {
            $rawLines = explode(StringHelpers::UNIX_LINE_ENDING, $rawCue);

            if (substr_count($rawLines[0], ",") !== 1) {
                throw new ParsingException("Block #$idx doesn't seem to have its timestamps on its first line!");
            }

            if (count($rawLines) < 2) {
                throw new ParsingException("Block #$idx doesn't have any text lines!");
            }

            $times = explode(",", $rawLines[0]);
            $subtitle->addCue(new SubtitleCue(
                $this->millisFromString($times[0]),
                $this->millisFromString($times[1]),
                array_map(fn (string $line): string => htmlspecialchars($line, ENT_NOQUOTES, "UTF-8"), array_slice($rawLines, 1))
            ));
        }

        return $subtitle;
    }


    private function millisFromString(string $timeString): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^(\d+):([0-5]\d):([0-5]\d)\.(\d{3})$/", $timeString, $matches)) {
            throw new ParsingException("The timeString-string of at least one cue could not be parsed: $timeString");
        }

        $hours   = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];
        $millis  = (int) $matches[4];

        return $hours * 3600 + $minutes * 60 + $seconds + $millis / 1000;
    }
}
