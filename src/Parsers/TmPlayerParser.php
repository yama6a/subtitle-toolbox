<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class TmPlayerParser extends SubtitleParser
{
    private const LINE_REGEX = '/^(\d+):([0-5]\d):([0-5]\d)(?:,(\d+))?[:=](.*)$/';


    protected function read(string $rawSubtitle): Subtitle
    {
        $entries    = [];
        $blockIndex = -1;
        foreach ($this->lines($rawSubtitle) as $lineIndex => $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === "") {
                continue;
            }

            $blockIndex++;
            if (!preg_match(self::LINE_REGEX, $rawLine, $matches)) {
                $lineNumber = $lineIndex + 1;
                $this->fail(new ParsingException("The line \"$rawLine\" is not a TMPlayer line.", $lineNumber), $lineNumber, $blockIndex, [$rawLine]);
                continue;
            }

            $time  = Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3]);
            $lines = $this->parseText($matches[5]);
            $last  = array_key_last($entries);
            // TMPlayer+ writes each line of a cue as its own entry, with the line number after a comma.
            if ((int) $matches[4] > 1 && $last !== null && $entries[$last]["time"] === $time) {
                $entries[$last]["lines"] = [...$entries[$last]["lines"], ...$lines];
                continue;
            }

            $entries[] = ["time" => $time, "lines" => $lines];
        }

        $subtitle   = new Subtitle();
        $parsedCues = [];
        foreach ($entries as $index => $entry) {
            // An entry without text ends the cue before it. TMPlayer writes one where a gap follows a cue.
            if ($entry["lines"] === []) {
                continue;
            }

            $end = isset($entries[$index + 1]) ? $entries[$index + 1]["time"] : $entry["time"] + $this->options->lastCueDuration;
            $parsedCues[] = new SubtitleCue($entry["time"], max($end, $entry["time"]), $entry["lines"]);
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * @return list<string>
     */
    private function parseText(string $text): array
    {
        return array_map(Markup::escapeText(...), $this->pipeLines($text));
    }
}
