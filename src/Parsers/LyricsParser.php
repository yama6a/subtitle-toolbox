<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class LyricsParser extends SubtitleParser
{
    const REGEX = "/^\[(\d{2,3}):([0-5]\d).(\d\d)\](.+)$/";


    public function parse(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeEOLs($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeSpaces($rawSubtitle);
        $rawSubtitle = StringHelpers::removeEmptyLines($rawSubtitle);
        $rawSubtitle = StringHelpers::trimEachLine($rawSubtitle);

        $lines     = explode(StringHelpers::UNIX_LINE_ENDING, $rawSubtitle);
        $subtitle  = new Subtitle();
        $lineCount = count($lines);

        foreach ($lines as $idx => $currentLine) {
            if (!preg_match(static::REGEX, $currentLine, $matches)) {
                $this->addIdTagToSubtitle($subtitle, $currentLine);
                continue;
            }

            $start = $matches[1] * 60 + $matches[2] + round($matches[3] / 100, 2);
            $line  = StringHelpers::cleanString($matches[4]);

            if ($line === "") {
                continue;
            }

            // find end timestamp from next cue's start-time
            $nextMatches = null;
            for ($i = $idx + 1; $i < $lineCount; $i++) {
                if (preg_match(static::REGEX, $lines[$i], $nextMatches)) {
                    break;
                }
            }
            if ($nextMatches) {
                $end = $nextMatches[1] * 60 + $nextMatches[2] + round($nextMatches[3] / 100);
            } else { // this is the final cue, so we have to assign an end time
                // just assume no cue will never have to be visible for longer than 10 seconds.
                $end = $start + 10;
            }

            $subtitle->addCue(new SubtitleCue($start, $end, $line));
        }

        return $subtitle;
    }


    /**
     * ToDo: Add ID tag parsing for lyrics... or whatever
     *
     * @param Subtitle $subtitle
     * @param          $currentLine
     */
    private function addIdTagToSubtitle(Subtitle $subtitle, string $currentLine)
    {
        return;
    }
}
