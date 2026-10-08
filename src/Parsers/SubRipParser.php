<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class SubRipParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::SubRip->value;

    protected function read(string $rawSubtitle): Subtitle
    {
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $index      = 0;
        foreach ($this->splitIntoBlocks($this->lines($rawSubtitle)) as $lineNumber => $rawLines) {
            foreach ($this->parseBlock($rawLines, $index++, $lineNumber) as $cue) {
                $parsedCues[] = $cue;
            }
        }

        return $subtitle->addCues($parsedCues);
    }


    /**
     * Yields the trimmed lines of each block between empty lines, keyed by the 1-based number of its first line.
     *
     * @param iterable<int, string> $lines keyed by the 0-based line number
     *
     * @return Generator<int, list<string>>
     *
     * @internal
     */
    public function splitIntoBlocks(iterable $lines): Generator
    {
        return $this->splitAtEmptyLines($lines);
    }


    /**
     * Returns the cues of one block from splitIntoBlocks(). In lenient mode, it skips or repairs a broken block and warns.
     *
     * @param list<string> $rawLines
     *
     * @return list<SubtitleCue>
     *
     * @internal
     */
    public function parseBlock(array $rawLines, int $index, int $lineNumber): array
    {
        if (!$this->options->lenient) {
            try {
                return [$this->parseCueBlock($rawLines, $index)];
            } catch (ParsingException $exception) {
                throw self::atLine($exception, $lineNumber);
            }
        }

        return $this->parseRepairedBlock(
            $rawLines,
            $lineNumber,
            $index,
            $this->isTimingLine(...),
            true,
            function (array $part, int $partLine) use ($index): SubtitleCue {
                $hasNumber = !$this->isTimingLine($part[0]);
                $cue       = $this->parseCueBlock($hasNumber ? $part : array_merge(["0"], $part), $index);
                if (!$hasNumber) {
                    $this->warn(
                        "Block #$index has no cue number on line $partLine. The parser read the cue without it.",
                        $partLine,
                        $index,
                        $part,
                        ParseWarningAction::Repaired
                    );
                }

                return $cue;
            }
        );
    }


    private function isTimingLine(string $line): bool
    {
        return preg_match("/^\d+:\d\d:\d\d\S* --> /", $line) === 1;
    }


    /**
     * Parses one cue block of trimmed lines without empty lines, as parse() splits the file.
     *
     * @internal
     */
    public function parseCueBlock(array $rawLines, int $idx): SubtitleCue
    {
        if (!is_numeric($rawLines[0])) {
            throw new ParsingException("Block #$idx has no cue number on its first line.");
        }

        if (!str_contains($rawLines[1] ?? "", ' --> ')) {
            throw new ParsingException("Block #$idx has no timing line on its second line.");
        }

        if (count($rawLines) < 3) {
            throw new ParsingException("Block #$idx has no text lines.");
        }

        $times       = explode('-->', $rawLines[1]);
        $coordinates = $this->extractCoordinates($times[1]);
        $cue         = new SubtitleCue(
            $this->secondsFromString($times[0]),
            $this->secondsFromString($times[1]),
            array_map($this->escapeText(...), array_slice($rawLines, 2))
        );
        $this->convertOverrideTags($cue);
        if ($coordinates !== null) {
            $cue->setFormatData(self::FORMAT_DATA_KEY, ["coordinates" => $coordinates]);
        }

        return $cue;
    }


    // Players show &amp; as typed, so every & is text.
    private function escapeText(string $line): string
    {
        $parts = preg_split('#(</?[a-zA-Z][a-zA-Z0-9]*(?:\s[^<>]*)?>)#', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = Markup::escapeText($part);
            }
        }

        return implode("", $parts);
    }


    private function secondsFromString(string $timeString): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^(\d{1,3}):([0-5]\d):([0-5]\d)(?:[,.](\d{1,3}))?$/", $timeString, $matches)) {
            throw new ParsingException("The time \"$timeString\" is not valid.");
        }

        return Timecode::toSeconds((int) $matches[1], (int) $matches[2], (int) $matches[3], $matches[4] ?? "");
    }


    /**
     * @return array{x1: int, x2: int, y1: int, y2: int}|null
     */
    private function extractCoordinates(string &$endTimeString): ?array
    {
        $pattern = "/^(.*?)\s+X1:(\d+)\s+X2:(\d+)\s+Y1:(\d+)\s+Y2:(\d+)\s*$/";
        if (!preg_match($pattern, $endTimeString, $matches)) {
            return null;
        }

        $endTimeString = $matches[1];

        return [
            "x1" => (int) $matches[2],
            "x2" => (int) $matches[3],
            "y1" => (int) $matches[4],
            "y2" => (int) $matches[5],
        ];
    }


    private function convertOverrideTags(SubtitleCue $cue): void
    {
        $alignment = null;
        $openTags  = [];
        $text      = preg_replace_callback(
            '/\{(\\\\[^{}]*)\}/',
            function (array $block) use (&$alignment, &$openTags): string {
                preg_match_all('/\\\\[^\\\\]*/', $block[1], $tags);
                $markup  = "";
                $unknown = "";
                foreach ($tags[0] as $tag) {
                    $tagAlignment = SsaOverrideTags::alignment($tag);
                    if ($tagAlignment !== null) {
                        $alignment ??= $tagAlignment;
                    } elseif (preg_match('/^\\\\([bius])([01])$/', $tag, $matches)) {
                        $markup .= $this->toggleTag($matches[1], $matches[2] === "1", $openTags);
                    } else {
                        $unknown .= $tag;
                    }
                }

                return $markup . ($unknown === "" ? "" : "{" . $unknown . "}");
            },
            $cue->getText()
        );

        foreach (array_reverse(array_keys($openTags)) as $tagName) {
            $text .= "</$tagName>";
        }

        $cue->setLines($text);
        $cue->setAlignment($alignment);
    }


    private function toggleTag(string $tagName, bool $open, array &$openTags): string
    {
        if ($open === isset($openTags[$tagName])) {
            return "";
        }

        if ($open) {
            $openTags[$tagName] = true;

            return "<$tagName>";
        }

        unset($openTags[$tagName]);

        return "</$tagName>";
    }
}
