<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Encoding\Cea608;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Formatters\Options\SccOptions;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\SccParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;
use SubtitleToolbox\WriteOptions;

/**
 * Writes pop-on captions for CEA-608 data channel 1, one byte pair per frame at 29.97 fps.
 *
 * @see http://www.theneitherworld.com/mcpoodle/SCC_TOOLS/DOCS/SCC_FORMAT.HTML
 */
class SccFormatter extends SubtitleFormatter
{
    protected const FORMAT_OPTIONS = SccOptions::class;

    private const MAX_LINES = 4;

    private const NAMED_COLORS = [
        "white" => 0, "lime" => 1, "blue" => 2, "cyan" => 3, "aqua" => 3, "red" => 4, "yellow" => 5, "magenta" => 6, "fuchsia" => 6,
    ];

    private const DEFAULT_ATTRIBUTES = ["color" => Cea608::WHITE, "italic" => false, "underline" => false];


    /**
     * @throws InvalidArgumentException for a cue with more than 4 lines, a line longer than 32 characters or a character that CEA-608 lacks.
     */
    public function format(Subtitle $subtitle, WriteOptions $options = new WriteOptions()): string
    {
        $dropFrame = $this->formatOptions($options)?->dropFrame ?? $subtitle->getFormatData(SccParser::FORMAT)["dropFrame"] ?? true;

        $cues = $subtitle->getCues();
        uasort($cues, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());

        $timeline     = [];
        $nextFree     = 0;
        $previousEnd  = null;
        foreach ($cues as $idx => $cue) {
            $load = $this->loadWords($cue, $idx);
            if ($load === []) {
                continue;
            }

            [$eoc, $edm] = $this->schedule(count($load), $this->secondsToFrame($cue->getStart()), $nextFree, $previousEnd);
            if ($edm !== null) {
                $timeline[$edm]     = $this->command(Cea608::ERASE_DISPLAYED_MEMORY);
                $timeline[$edm + 1] = $this->command(Cea608::ERASE_DISPLAYED_MEMORY);
            }
            $frame = $eoc - 1;
            foreach (array_reverse($load) as $word) {
                while (isset($timeline[$frame])) {
                    $frame--;
                }
                $timeline[$frame--] = $word;
            }
            $timeline[$eoc]     = $this->command(Cea608::END_OF_CAPTION);
            $timeline[$eoc + 1] = $this->command(Cea608::END_OF_CAPTION);

            $nextFree    = $eoc + 2;
            $previousEnd = $this->secondsToFrame($cue->getEnd());
        }
        if ($previousEnd !== null) {
            $edm                = max($previousEnd, $nextFree);
            $timeline[$edm]     = $this->command(Cea608::ERASE_DISPLAYED_MEMORY);
            $timeline[$edm + 1] = $this->command(Cea608::ERASE_DISPLAYED_MEMORY);
        }

        return $this->applyOutputOptions($this->writeLines($timeline, $dropFrame), $options);
    }


    /**
     * Finds the frame of the EOC that shows the caption, and the frame of the EDM that erases the caption before it, if any.
     * The load goes into the free frames before the EOC. When they are too few, the EOC comes later than the cue start.
     *
     * @return array{int, ?int}
     */
    private function schedule(int $loadLength, int $start, int $nextFree, ?int $previousEnd): array
    {
        $eoc = max($start, $nextFree + $loadLength);
        while (true) {
            $edm = null;
            if ($previousEnd !== null && $previousEnd < $eoc && max($previousEnd, $nextFree) + 1 < $eoc) {
                $edm = max($previousEnd, $nextFree);
            }

            $free = $eoc - $nextFree - ($edm === null ? 0 : 2);
            if ($free >= $loadLength) {
                return [$eoc, $edm];
            }
            $eoc += $loadLength - $free;
        }
    }


    /**
     * Returns the words that load the caption into non-displayed memory, without the EOC, or [] for a cue without text.
     *
     * @return list<int>
     */
    private function loadWords(SubtitleCue $cue, int|string $idx): array
    {
        $lines = [];
        foreach ($cue->getLines() as $line) {
            $characters = $this->characters($line);
            if ($characters !== []) {
                $lines[] = $characters;
            }
        }
        if ($lines === []) {
            return [];
        }

        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException("Cue #$idx at {$cue->getStart()} s has " . count($lines) . " lines, " .
                                               "but SCC allows " . self::MAX_LINES . ". Call wrapLines(32, 4) first.");
        }
        foreach ($lines as $characters) {
            if (count($characters) > Cea608::COLUMNS) {
                throw new InvalidArgumentException("Cue #$idx at {$cue->getStart()} s has a line with " . count($characters) .
                                                   " characters, but SCC allows " . Cea608::COLUMNS . ". Call wrapLines(32, 4) first.");
            }
            foreach ($characters as $character) {
                if (Cea608::encodeCharacter($character["char"]) === null) {
                    throw new InvalidArgumentException("Cue #$idx at {$cue->getStart()} s has the character \"{$character["char"]}\", " .
                                                       "which CEA-608 cannot show.");
                }
            }
        }

        $cells     = array_map(fn (array $characters): array => $this->cells($characters), $lines);
        $positions = $this->positions($cue, $cells);
        $words     = [$this->command(Cea608::ERASE_NON_DISPLAYED), $this->command(Cea608::ERASE_NON_DISPLAYED),
                      $this->command(Cea608::RESUME_CAPTION_LOADING), $this->command(Cea608::RESUME_CAPTION_LOADING)];
        foreach ($cells as $lineIdx => $lineCells) {
            array_push($words, ...$this->rowWords($positions[$lineIdx][0], $positions[$lineIdx][1], $lineCells));
        }

        return $words;
    }


    /**
     * Splits a line of core markup into characters with their colour, italics and underline, without the spaces at both ends.
     *
     * @return list<array{char: string, color: int, italic: bool, underline: bool}>
     */
    private function characters(string $line): array
    {
        $italic     = 0;
        $underline  = 0;
        $colors     = [];
        $characters = [];
        foreach (preg_split("/(<[^>]*>)/", $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if (preg_match("/^<\s*(\/?)\s*([a-z]+)\b([^>]*)>$/i", $part, $tag)) {
                $closing = $tag[1] === "/";
                switch (strtolower($tag[2])) {
                    case "i":
                        $italic = max(0, $italic + ($closing ? -1 : 1));
                        break;
                    case "u":
                        $underline = max(0, $underline + ($closing ? -1 : 1));
                        break;
                    case "font":
                        if ($closing) {
                            array_pop($colors);
                        } else {
                            $colors[] = $this->fontColor($tag[3]) ?? end($colors) ?: Cea608::WHITE;
                        }
                        break;
                }
                continue;
            }
            if ($part[0] === "<") {
                continue;
            }

            $text = str_replace(["\u{A0}", "\t"], " ", Markup::decodeEntities($part));
            foreach (Markup::characters($text) as $character) {
                $characters[] = [
                    "char"      => $character,
                    "color"     => end($colors) ?: Cea608::WHITE,
                    "italic"    => $italic > 0,
                    "underline" => $underline > 0,
                ];
            }
        }

        while ($characters !== [] && $characters[0]["char"] === " ") {
            array_shift($characters);
        }
        while ($characters !== [] && $characters[count($characters) - 1]["char"] === " ") {
            array_pop($characters);
        }

        return $characters;
    }


    private function fontColor(string $attributes): ?int
    {
        if (!preg_match("/\bcolor\s*=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s\"']+))/i", $attributes, $color)) {
            return null;
        }

        $color = strtolower(trim(Markup::decodeEntities(($color[1] ?? "") . ($color[2] ?? "") . ($color[3] ?? ""))));
        $index = array_search(substr($color, 0, 7), Cea608::COLORS, true);

        return $index !== false ? $index : self::NAMED_COLORS[$color] ?? null;
    }


    /**
     * Turns the characters into cells. A style change takes a mid-row code, which shows as a space. The code replaces
     * the space before the change when there is one. Styles that need more than 32 cells are dropped for the line.
     *
     * @return list<array{char?: string, midRow?: int}>
     */
    private function cells(array $characters): array
    {
        $cells   = [];
        $current = self::DEFAULT_ATTRIBUTES;
        foreach ($characters as $character) {
            $wanted = ["color" => $character["color"], "italic" => $character["italic"], "underline" => $character["underline"]];
            if ($character["char"] !== " " && $wanted !== $current) {
                $codes = $this->midRowCodes($current, $wanted);
                for ($idx = 0; $idx < count($codes) && $cells !== [] && ($cells[count($cells) - 1]["char"] ?? null) === " "; $idx++) {
                    array_pop($cells);
                }
                foreach ($codes as $code) {
                    $cells[] = ["midRow" => $code];
                }
                $current = $wanted;
            }
            $cells[] = ["char" => $character["char"]];
        }

        if (count($cells) > Cea608::COLUMNS) {
            return array_map(fn (array $character): array => ["char" => $character["char"]], $characters);
        }

        return $cells;
    }


    /**
     * Returns the second bytes of the mid-row codes that change the style. A colour code turns italics off,
     * and the italics code keeps the colour, as 47 CFR 15.119 (h)(1)(ii) says.
     *
     * @return list<int>
     */
    private function midRowCodes(array $from, array $to): array
    {
        if ($to["color"] !== $from["color"] || ($from["italic"] && !$to["italic"]) || (!$to["italic"] && $to["underline"] !== $from["underline"])) {
            $codes = [Cea608::encodeMidRow($to["color"], !$to["italic"] && $to["underline"])];
            if ($to["italic"]) {
                $codes[] = Cea608::encodeMidRow(null, $to["underline"]);
            }

            return $codes;
        }

        return [Cea608::encodeMidRow(null, $to["underline"])];
    }


    /**
     * Returns the row and the text column of each line. Rows and columns from the scc format data win when they
     * still fit the lines and the alignment. Otherwise the alignment sets them, with centred lines at the bottom by default.
     *
     * @param list<list<array>> $cells
     * @return list<array{int, int}>
     */
    private function positions(SubtitleCue $cue, array $cells): array
    {
        $count     = count($cells);
        $alignment = $cue->getAlignment() ?? 2;
        $stored    = $cue->getFormatData(SccParser::FORMAT);
        $rows      = $stored["rows"] ?? null;
        $columns   = $stored["columns"] ?? null;
        $storedOk  = is_array($rows) && is_array($columns) && count($rows) === $count && count($columns) === $count
                     && array_is_list($rows) && array_is_list($columns)
                     && (($rows[0] ?? 0) <= 4) === in_array($alignment, [7, 8, 9], true);
        for ($idx = 0; $storedOk && $idx < $count; $idx++) {
            $storedOk = is_int($rows[$idx]) && is_int($columns[$idx]) && $rows[$idx] >= 1 && $rows[$idx] <= Cea608::ROWS
                        && ($idx === 0 || $rows[$idx] > $rows[$idx - 1]);
        }

        $firstRow = match (true) {
            in_array($alignment, [7, 8, 9], true) => 1,
            in_array($alignment, [4, 5, 6], true) => intdiv(Cea608::ROWS - $count, 2) + 1,
            default                               => Cea608::ROWS - $count + 1,
        };

        $positions = [];
        foreach ($cells as $idx => $lineCells) {
            $width = count($lineCells) - $this->leadingMidRowCount($lineCells);
            if ($storedOk) {
                $positions[] = [$rows[$idx], max(0, min($columns[$idx], Cea608::COLUMNS - $width))];
                continue;
            }

            $column      = match (true) {
                in_array($alignment, [1, 4, 7], true) => 0,
                in_array($alignment, [3, 6, 9], true) => Cea608::COLUMNS - $width,
                default                               => intdiv(Cea608::COLUMNS - $width, 2),
            };
            $positions[] = [$firstRow + $idx, $column];
        }

        return $positions;
    }


    private function leadingMidRowCount(array $cells): int
    {
        $count = 0;
        while (isset($cells[$count]["midRow"])) {
            $count++;
        }

        return $count;
    }


    /**
     * Returns the words of one row: the PAC, a tab offset, the mid-row codes and the characters.
     * Control codes and special characters go twice. A single standard character before a code gets the filler byte 0x80.
     *
     * @return list<int>
     */
    private function rowWords(int $row, int $column, array $cells): array
    {
        $leading = $this->leadingMidRowCount($cells);
        $start   = $column - $leading;
        if ($start < 0) {
            $attributes = self::DEFAULT_ATTRIBUTES;
            for ($idx = 0; $idx < $leading; $idx++) {
                $midRow     = Cea608::decodeMidRow($cells[$idx]["midRow"]);
                $attributes = ["color" => $midRow["color"] ?? $attributes["color"], "italic" => $midRow["italic"], "underline" => $midRow["underline"]];
            }

            $cells = array_slice($cells, $leading);
            if ($attributes["italic"] && $attributes["color"] !== Cea608::WHITE) {
                array_unshift($cells, ["midRow" => Cea608::encodeMidRow(null, $attributes["underline"])]);
                $pac = Cea608::encodePac($row, 0, $attributes["color"]);
            } else {
                $pac = Cea608::encodePac($row, 0, $attributes["color"], $attributes["italic"], $attributes["underline"]);
            }
            $start = 0;
        } else {
            $pac = Cea608::encodePac($row, intdiv($start, 4) * 4);
        }

        $words = [$this->word(...$pac), $this->word(...$pac)];
        if ($start % 4 > 0) {
            $tab   = $this->word(0x17, 0x20 + $start % 4);
            $words = [...$words, $tab, $tab];
        }

        $pending = null;
        foreach ($cells as $cell) {
            $code = isset($cell["midRow"]) ? ["pair" => [0x11, $cell["midRow"]]] : Cea608::encodeCharacter($cell["char"]);
            if (isset($code["byte"])) {
                if ($pending === null) {
                    $pending = $code["byte"];
                } else {
                    $words[] = $this->word($pending, $code["byte"]);
                    $pending = null;
                }
            }
            if (isset($code["pair"])) {
                if ($pending !== null) {
                    $words[] = $this->word($pending, 0x00);
                    $pending = null;
                }
                $pair    = $this->word(...$code["pair"]);
                $words   = [...$words, $pair, $pair];
            }
        }
        if ($pending !== null) {
            $words[] = $this->word($pending, 0x00);
        }

        return $words;
    }


    private function command(int $command): int
    {
        return $this->word(0x14, $command);
    }


    private function word(int $first, int $second): int
    {
        return (Cea608::withParity($first) << 8) | Cea608::withParity($second);
    }


    private function secondsToFrame(float $seconds): int
    {
        return (int) round(max(0.0, $seconds) * 30000 / 1001);
    }


    /**
     * Writes one line per run of consecutive frames, with an empty line between lines.
     *
     * @param array<int, int> $wordsByFrame
     */
    private function writeLines(array $wordsByFrame, bool $dropFrame): string
    {
        ksort($wordsByFrame);
        $frameRate = new FrameRate(30000 / 1001);
        $lines     = [];
        $previous  = null;
        foreach ($wordsByFrame as $frame => $word) {
            if ($previous === null || $frame !== $previous + 1) {
                [$hours, $minutes, $seconds, $frames] = Timecode::frameNumber($frame, $frameRate, $dropFrame);
                $lines[] = sprintf("%02d:%02d:%02d%s%02d\t%04x", $hours, $minutes, $seconds, $dropFrame ? ";" : ":", $frames, $word);
            } else {
                $lines[count($lines) - 1] .= sprintf(" %04x", $word);
            }
            $previous = $frame;
        }

        $output = SccParser::HEADER . StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING;
        foreach ($lines as $line) {
            $output .= $line . StringHelpers::UNIX_LINE_ENDING . StringHelpers::UNIX_LINE_ENDING;
        }

        return rtrim($output, StringHelpers::UNIX_LINE_ENDING) . StringHelpers::UNIX_LINE_ENDING;
    }
}
