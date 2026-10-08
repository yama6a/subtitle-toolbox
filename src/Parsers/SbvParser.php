<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class SbvParser extends SubtitleParser
{
    protected function read(string $rawSubtitle): Subtitle
    {
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $idx        = 0;
        foreach ($this->splitAtEmptyLines($this->lines($rawSubtitle)) as $lineNumber => $rawLines) {
            $cues = $this->parseRepairedBlock($rawLines, $lineNumber, $idx, $this->isTimingLine(...), false,
                                              fn (array $part, int $partLine): SubtitleCue => $this->parseCueBlock($part, $idx, $partLine));
            array_push($parsedCues, ...$cues);
            $idx++;
        }

        return $subtitle->addCues($parsedCues);
    }


    private function parseCueBlock(array $rawLines, int $idx, int $lineNumber): SubtitleCue
    {
        if (substr_count($rawLines[0], ",") !== 1) {
            throw new ParsingException("Block #$idx has no timing line on its first line.", $lineNumber);
        }

        if (count($rawLines) < 2) {
            throw new ParsingException("Block #$idx has no text lines.", $lineNumber);
        }

        $times = explode(",", $rawLines[0]);

        return new SubtitleCue(
            $this->secondsFromString($times[0], $lineNumber),
            $this->secondsFromString($times[1], $lineNumber),
            array_map(Markup::escapeText(...), array_slice($rawLines, 1))
        );
    }


    private function isTimingLine(string $line): bool
    {
        return preg_match("/^\d+:\d\d:\d\d\.\d+,/", $line) === 1;
    }


    private function secondsFromString(string $timeString, int $lineNumber): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^(\d+):([0-5]\d):([0-5]\d)\.(\d{3})$/", $timeString, $matches)) {
            throw new ParsingException("The time \"$timeString\" is not valid.", $lineNumber);
        }

        return Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]);
    }
}
