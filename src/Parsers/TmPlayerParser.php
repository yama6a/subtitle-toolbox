<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class TmPlayerParser extends SubtitleParser
{
    public const DEFAULT_LAST_CUE_DURATION = 4;

    private const LINE_REGEX = '/^(\d+):([0-5]\d):([0-5]\d)(?:,(\d+))?[:=](.*)$/';

    private float $lastCueDuration;


    /**
     * Creates a parser that ends the last cue the given number of seconds after its start.
     */
    public function __construct(float $lastCueDuration = self::DEFAULT_LAST_CUE_DURATION)
    {
        if ($lastCueDuration < 0) {
            throw new InvalidArgumentException("The last cue duration must not be negative!");
        }

        $this->lastCueDuration = $lastCueDuration;
    }


    public function parse(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $rawSubtitle    = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle    = StringHelpers::normalizeEOLs($rawSubtitle);

        // An entry without text ends the cue before it. TMPlayer writes one where a gap follows a cue.
        $entries    = [];
        $blockIndex = -1;
        foreach (explode(StringHelpers::UNIX_LINE_ENDING, $rawSubtitle) as $lineIndex => $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === "") {
                continue;
            }

            $blockIndex++;
            if (!preg_match(self::LINE_REGEX, $rawLine, $matches)) {
                $lineNumber = $lineIndex + 1;
                $this->fail(new ParsingException("Line $lineNumber is not a TMPlayer line: $rawLine", $lineNumber), $lineNumber, $blockIndex, [$rawLine]);
                continue;
            }

            $time  = (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
            $lines = $this->parseText($matches[5]);
            $last  = array_key_last($entries);
            // TMPlayer+ writes each line of a cue as its own entry, with the line number after a comma.
            if ((int) $matches[4] > 1 && $last !== null && $entries[$last]["time"] === $time) {
                $entries[$last]["lines"] = [...$entries[$last]["lines"], ...$lines];
                continue;
            }

            $entries[] = ["time" => $time, "lines" => $lines];
        }

        $subtitle = new Subtitle();
        foreach ($entries as $index => $entry) {
            if ($entry["lines"] === []) {
                continue;
            }

            $end = isset($entries[$index + 1]) ? $entries[$index + 1]["time"] : $entry["time"] + $this->lastCueDuration;
            $subtitle->addCue(new SubtitleCue($entry["time"], max($end, $entry["time"]), $entry["lines"]), false);
        }

        return $subtitle->reIndexCues();
    }


    /**
     * @return list<string>
     */
    private function parseText(string $text): array
    {
        return array_values(array_filter(
            array_map(fn (string $line): string => Markup::escapeText(trim($line)), explode("|", $text)),
            fn (string $line): bool => $line !== ""
        ));
    }
}
