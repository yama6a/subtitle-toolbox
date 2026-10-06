<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\ChapterReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

// Spec: https://mkvtoolnix.download/doc/mkvmerge.html#mkvmerge.chapters.simple. The line patterns are the ones
// of parse_simple() in mkvtoolnix src/common/chapters/chapters.cpp.
final class OgmChaptersParser extends SubtitleParser
{
    private const TIMESTAMP_LINE = '/^CHAPTER\d+\s*=\s*(\d+)\s*:\s*(\d+)\s*:\s*(\d+)\s*[.,]\s*(\d{1,9})/';
    private const NAME_LINE      = '/^CHAPTER\d+NAME\s*=(.*)$/';


    protected static function formatOptionsClass(): string
    {
        return ChapterReadOptions::class;
    }


    protected function read(string $rawSubtitle): Subtitle
    {
        $lines = $this->lines($rawSubtitle);

        $chapters = [];
        $start    = null;
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }

            if ($start === null) {
                $start = $this->readTimestampLine($line, $index + 1);
            } elseif (preg_match(self::NAME_LINE, $line, $matches) === 1) {
                $chapters[] = new SubtitleCue($start, $start, Markup::escapeText($matches[1]));
                $start      = null;
            } else {
                throw new ParsingException("Line " . ($index + 1) . " is not a CHAPTERxxNAME= line: $line", $index + 1);
            }
        }

        return (new Subtitle())->addCues($this->endChapters($chapters));
    }


    private function readTimestampLine(string $line, int $lineNumber): float
    {
        if (preg_match(self::TIMESTAMP_LINE, $line, $matches) !== 1) {
            throw new ParsingException("Line $lineNumber is not a CHAPTERxx= line: $line", $lineNumber);
        }
        if ((int) $matches[2] > 59 || (int) $matches[3] > 59) {
            throw new ParsingException("Line $lineNumber has a minute or second above 59: $line", $lineNumber);
        }

        return Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4]);
    }
}
