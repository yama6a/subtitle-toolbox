<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class Mpl2Parser extends SubtitleParser
{
    /** @internal */
    public const DECISECONDS_PER_SECOND = 10;

    private const CUE_REGEX = '/^\[(\d+)\]\[(\d+)\](.*)$/';


    protected function read(string $content): Subtitle
    {
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $blockIndex = 0;
        foreach ($this->lines($content) as $lineIndex => $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === "") {
                continue;
            }

            $lineNumber = $lineIndex + 1;
            try {
                if (!preg_match(self::CUE_REGEX, $rawLine, $matches)) {
                    throw new ParsingException("The line \"$rawLine\" is not an MPL2 cue.", $lineNumber);
                }
                $parsedCues[] = new SubtitleCue(
                    self::boundedTime((int) $matches[1] / self::DECISECONDS_PER_SECOND, "[$matches[1]]", $lineNumber),
                    self::boundedTime((int) $matches[2] / self::DECISECONDS_PER_SECOND, "[$matches[2]]", $lineNumber),
                    $this->parseText($matches[3])
                );
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $blockIndex, [$rawLine]);
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
