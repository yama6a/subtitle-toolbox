<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class Mpl2Parser extends SubtitleParser
{
    private const CUE_REGEX = '/^\[(\d+)\]\[(\d+)\](.*)$/';


    public function parse(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $rawSubtitle    = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle    = StringHelpers::normalizeEOLs($rawSubtitle);

        $subtitle   = new Subtitle();
        $blockIndex = 0;
        foreach (explode(StringHelpers::UNIX_LINE_ENDING, $rawSubtitle) as $lineIndex => $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === "") {
                continue;
            }

            if (preg_match(self::CUE_REGEX, $rawLine, $matches)) {
                $subtitle->addCue(new SubtitleCue((int) $matches[1] / 10, (int) $matches[2] / 10, $this->parseText($matches[3])), false);
            } else {
                $lineNumber = $lineIndex + 1;
                $this->fail(new ParsingException("Line $lineNumber is not an MPL2 cue: $rawLine", $lineNumber), $lineNumber, $blockIndex, [$rawLine]);
            }
            $blockIndex++;
        }

        return $subtitle->reIndexCues();
    }


    /**
     * @return list<string>
     */
    private function parseText(string $text): array
    {
        $lines = [];
        foreach (explode("|", $text) as $line) {
            $line = trim($line);
            if (str_starts_with($line, "/")) {
                $line = trim(substr($line, 1));
                if ($line !== "") {
                    $lines[] = "<i>" . Markup::escapeText($line) . "</i>";
                }
            } elseif ($line !== "") {
                $lines[] = Markup::escapeText($line);
            }
        }

        return $lines;
    }
}
