<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

// Spec: https://mkvtoolnix.download/doc/mkvmerge.html#mkvmerge.chapters.simple. The line patterns are the ones
// of parse_simple() in mkvtoolnix src/common/chapters/chapters.cpp.
class OgmChaptersParser extends SubtitleParser
{
    private const TIMESTAMP_LINE = '/^CHAPTER\d+\s*=\s*(\d+)\s*:\s*(\d+)\s*:\s*(\d+)\s*[.,]\s*(\d{1,9})/';
    private const NAME_LINE      = '/^CHAPTER\d+NAME\s*=(.*)$/';


    /**
     * Creates a parser that ends the last chapter at $mediaDuration seconds, or at its own start when it is null.
     */
    public function __construct(private readonly ?float $mediaDuration = null)
    {
    }


    public function parse(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $lines          = explode("\n", StringHelpers::normalizeEOLs(StringHelpers::removeUtf8Bom($rawSubtitle)));

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

        usort($chapters, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());
        $subtitle = new Subtitle();
        foreach ($chapters as $index => $cue) {
            $cue->setEnd(isset($chapters[$index + 1]) ? $chapters[$index + 1]->getStart() : max($cue->getStart(), $this->mediaDuration ?? 0));
            $subtitle->addCue($cue, false);
        }

        return $subtitle;
    }


    private function readTimestampLine(string $line, int $lineNumber): float
    {
        if (preg_match(self::TIMESTAMP_LINE, $line, $matches) !== 1) {
            throw new ParsingException("Line $lineNumber is not a CHAPTERxx= line: $line", $lineNumber);
        }
        if ((int) $matches[2] > 59 || (int) $matches[3] > 59) {
            throw new ParsingException("Line $lineNumber has a minute or second above 59: $line", $lineNumber);
        }

        return (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3] + (float) ("0." . $matches[4]);
    }
}
