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
        $rawSubtitle = StringHelpers::trimEachLine($rawSubtitle);
        $rawSubtitle = StringHelpers::removeDoubleEmptyLines($rawSubtitle);
        $rawSubtitle = trim($rawSubtitle);  // remove empty lines on the top and bottom of the file

        $rawCues  = explode(StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING, $rawSubtitle);
        $subtitle = new Subtitle();
        foreach ($rawCues as $idx => $rawCue) {
            $rawLines = explode(StringHelpers::UNIX_LINE_ENDING, $rawCue);

            if (!is_numeric($rawLines[0])) {
                throw new ParsingException("Block #$idx doesn't seem to have a cue-number on its first line!");
            }

            if (!str_contains($rawLines[1] ?? "", ' --> ')) {
                throw new ParsingException("Block #$idx doesn't seem to have its timestamps on its second line!");
            }

            if (count($rawLines) < 3) {
                throw new ParsingException("Block #$idx doesn't have any text lines!");
            }

            $times       = explode('-->', $rawLines[1]);
            $coordinates = $this->extractCoordinates($times[1]);
            $cue         = new SubtitleCue(
                $this->millisFromString($times[0]),
                $this->millisFromString($times[1]),
                array_slice($rawLines, 2)
            );
            if ($coordinates !== null) {
                $cue->setFormatData("srt", ["coordinates" => $coordinates]);
            }
            $subtitle->addCue($cue);
        }

        return $subtitle;
    }


    private function millisFromString(string $timeString): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^(\d{1,3}):([0-5]\d):([0-5]\d)[,.](\d{1,3})$/", $timeString, $matches)) {
            throw new ParsingException("The timeString-string of at least one cue could not be parsed: $timeString");
        }

        $hours   = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];
        $millis  = (int) str_pad($matches[4], 3, "0");

        return $hours * 3600 + $minutes * 60 + $seconds + $millis / 1000;
    }


    /**
     * @return array{x1: int, x2: int, y1: int, y2: int}|null
     */
    private function extractCoordinates(string &$endTimeString): ?array
    {
        $pattern = "/^(.*?)\s+X1:(\d+)\s+X2:(\d+)\s+Y1:(\d+)\s+Y2:(\d+)\s*$/";
        if (!preg_match($pattern, $endTimeString, $matches)) {
            return null;
        }

        $endTimeString = $matches[1];

        return [
            "x1" => (int) $matches[2],
            "x2" => (int) $matches[3],
            "y1" => (int) $matches[4],
            "y2" => (int) $matches[5],
        ];
    }
}
