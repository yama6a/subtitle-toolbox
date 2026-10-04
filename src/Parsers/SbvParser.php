<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class SbvParser extends SubtitleParser
{
    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $rawSubtitle    = StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($rawSubtitle));

        $subtitle = new Subtitle();
        $idx      = 0;
        foreach ($this->splitAtEmptyLines(explode(StringHelpers::UNIX_LINE_ENDING, $rawSubtitle)) as $lineNumber => $rawLines) {
            if ($this->lenient && $rawLines === [""]) {
                $this->warn("The file has no cues.", $lineNumber, $idx, $rawLines, ParseWarning::SKIPPED);
                break;
            }

            $parts = $this->repairMissingEmptyLines($rawLines, $lineNumber, $idx, $this->isTimingLine(...), false);
            foreach ($parts as $offset => $part) {
                try {
                    $subtitle->addCue($this->parseCueBlock($part, $idx), false);
                } catch (ParsingException $exception) {
                    $this->fail($exception, $lineNumber + $offset, $idx, $part);
                }
            }
            $idx++;
        }

        return $subtitle->reIndexCues();
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
            $this->millisFromString($times[0]),
            $this->millisFromString($times[1]),
            array_map(Markup::escapeText(...), array_slice($rawLines, 1))
        );
    }


    private function isTimingLine(string $line): bool
    {
        return preg_match("/^\d+:\d\d:\d\d\.\d+,/", $line) === 1;
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
