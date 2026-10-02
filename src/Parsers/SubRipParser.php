<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SubRipParser extends SubtitleParser
{
    // Legacy SSA codes: 1 to 3 are bottom, +4 is top, +8 is middle.
    private const LEGACY_ALIGNMENTS = [1 => 1, 2 => 2, 3 => 3, 5 => 7, 6 => 8, 7 => 9, 9 => 4, 10 => 5, 11 => 6];

    public function parse(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeEOLs($rawSubtitle);
        $rawSubtitle = StringHelpers::normalizeSpaces($rawSubtitle);
        $rawSubtitle = StringHelpers::trimEachLine($rawSubtitle);
        $rawSubtitle = StringHelpers::removeDoubleEmptyLines($rawSubtitle);
        $rawSubtitle = trim($rawSubtitle);  // remove empty lines on the top and bottom of the file

        $rawCues  = explode(StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING, $rawSubtitle);
        $subtitle = new Subtitle();
        foreach ($rawCues as $idx => $rawCue) {
            $subtitle->addCue($this->parseCueBlock(explode(StringHelpers::UNIX_LINE_ENDING, $rawCue), $idx));
        }

        return $subtitle;
    }


    /**
     * Parses one cue block of trimmed lines without empty lines, as parse() splits the file.
     */
    public function parseCueBlock(array $rawLines, int $idx): SubtitleCue
    {
        if (!is_numeric($rawLines[0])) {
            throw new ParsingException("Block #$idx doesn't seem to have a cue-number on its first line!");
        }

        if (!str_contains($rawLines[1] ?? "", ' --> ')) {
            throw new ParsingException("Block #$idx doesn't seem to have its timestamps on its second line!");
        }

        if (count($rawLines) < 3) {
            throw new ParsingException("Block #$idx doesn't have any text lines!");
        }

        $times       = explode('-->', $rawLines[1]);
        $coordinates = $this->extractCoordinates($times[1]);
        $cue         = new SubtitleCue(
            $this->millisFromString($times[0]),
            $this->millisFromString($times[1]),
            array_map($this->escapeText(...), array_slice($rawLines, 2))
        );
        $this->convertOverrideTags($cue);
        if ($coordinates !== null) {
            $cue->setFormatData("srt", ["coordinates" => $coordinates]);
        }

        return $cue;
    }


    // Players show &amp; as typed, so every & is text. str_replace keeps bytes of files that are not UTF-8.
    private function escapeText(string $line): string
    {
        $parts = preg_split('#(</?[a-zA-Z][a-zA-Z0-9]*(?:\s[^<>]*)?>)#', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = str_replace(["&", "<", ">"], ["&amp;", "&lt;", "&gt;"], $part);
            }
        }

        return implode("", $parts);
    }


    private function millisFromString(string $timeString): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^(\d{1,3}):([0-5]\d):([0-5]\d)(?:[,.](\d{1,3}))?$/", $timeString, $matches)) {
            throw new ParsingException("The timeString-string of at least one cue could not be parsed: $timeString");
        }

        $hours   = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];
        $millis  = (int) str_pad($matches[4] ?? "", 3, "0");

        return $hours * 3600 + $minutes * 60 + $seconds + $millis / 1000;
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
                    if (preg_match('/^\\\\an([1-9])$/', $tag, $matches)) {
                        $alignment ??= (int) $matches[1];
                    } elseif (preg_match('/^\\\\a(\d{1,2})$/', $tag, $matches)
                              && isset(self::LEGACY_ALIGNMENTS[(int) $matches[1]])) {
                        $alignment ??= self::LEGACY_ALIGNMENTS[(int) $matches[1]];
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
