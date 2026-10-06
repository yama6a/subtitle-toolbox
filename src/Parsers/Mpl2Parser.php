<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class Mpl2Parser extends SubtitleParser
{
    private const CUE_REGEX = '/^\[(\d+)\]\[(\d+)\](.*)$/';


    protected function read(string $rawSubtitle): Subtitle
    {
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $blockIndex = 0;
        foreach ($this->lines($rawSubtitle) as $lineIndex => $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === "") {
                continue;
            }

            if (preg_match(self::CUE_REGEX, $rawLine, $matches)) {
                $parsedCues[] = new SubtitleCue((int) $matches[1] / 10, (int) $matches[2] / 10, $this->parseText($matches[3]));
            } else {
                $lineNumber = $lineIndex + 1;
                $this->fail(new ParsingException("Line $lineNumber is not an MPL2 cue: $rawLine", $lineNumber), $lineNumber, $blockIndex, [$rawLine]);
            }
            $blockIndex++;
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * @return list<string>
     */
    private function parseText(string $text): array
    {
        $lines = [];
        foreach ($this->pipeLines($text) as $line) {
            $italic = str_starts_with($line, "/");
            $line   = $italic ? trim(substr($line, 1)) : $line;
            if ($line !== "") {
                $lines[] = $italic ? "<i>" . Markup::escapeText($line) . "</i>" : Markup::escapeText($line);
            }
        }

        return $lines;
    }
}
