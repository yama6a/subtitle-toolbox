<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class SbvParser extends SubtitleParser
{
    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $rawSubtitle    = StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($rawSubtitle));

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $idx        = 0;
        foreach ($this->splitAtEmptyLines(explode(LineEnding::Lf->value, $rawSubtitle)) as $lineNumber => $rawLines) {
            $parts = $this->repairMissingEmptyLines($rawLines, $lineNumber, $idx, $this->isTimingLine(...), false);
            foreach ($parts as $offset => $part) {
                try {
                    $parsedCues[] = $this->parseCueBlock($part, $idx);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $lineNumber + $offset, $idx, $part);
                }
            }
            $idx++;
        }

        return $subtitle->addCues($parsedCues);
    }


    private function parseCueBlock(array $rawLines, int $idx): SubtitleCue
    {
        if (substr_count($rawLines[0], ",") !== 1) {
            throw new ParsingException("Block #$idx doesn't seem to have its timestamps on its first line!");
        }

        if (count($rawLines) < 2) {
            throw new ParsingException("Block #$idx doesn't have any text lines!");
        }

        $times = explode(",", $rawLines[0]);

        return new SubtitleCue(
            $this->secondsFromString($times[0]),
            $this->secondsFromString($times[1]),
            array_map(Markup::escapeText(...), array_slice($rawLines, 1))
        );
    }


    private function isTimingLine(string $line): bool
    {
        return preg_match("/^\d+:\d\d:\d\d\.\d+,/", $line) === 1;
    }


    private function secondsFromString(string $timeString): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^(\d+):([0-5]\d):([0-5]\d)\.(\d{3})$/", $timeString, $matches)) {
            throw new ParsingException("The timeString-string of at least one cue could not be parsed: $timeString");
        }

        return Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]);
    }
}
