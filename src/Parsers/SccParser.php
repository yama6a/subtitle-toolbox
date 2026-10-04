<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Encoding\Cea608;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * Reads Scenarist Closed Captions: CEA-608 byte pairs, one pair per frame at 29.97 fps, after a SMPTE time code.
 * The decoder follows the screen model of 47 CFR 15.119 and starts a new cue at each change of the displayed captions.
 *
 * @see http://www.theneitherworld.com/mcpoodle/SCC_TOOLS/DOCS/SCC_FORMAT.HTML
 * @see https://www.govinfo.gov/content/pkg/CFR-2010-title47-vol1/xml/CFR-2010-title47-vol1-sec15-119.xml
 */
final class SccParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::Scc->value;
    public const HEADER = "Scenarist_SCC V1.0";

    public const MODE_POP_ON   = "pop-on";
    public const MODE_ROLL_UP  = "roll-up";
    public const MODE_PAINT_ON = "paint-on";
    private const MODE_TEXT    = "text";

    private const DEFAULT_ATTRIBUTES = ["color" => Cea608::WHITE, "italic" => false, "underline" => false];

    private int $channel;

    /** @var array<int, array<int, ?array{char: string, color: int, italic: bool, underline: bool}>> */
    private array $displayed = [];

    /** @var array<int, array<int, ?array{char: string, color: int, italic: bool, underline: bool}>> */
    private array $nonDisplayed = [];

    private string $mode = self::MODE_POP_ON;

    private int $row = Cea608::ROWS;

    // 32 means that the last character went to the last column.
    private int $column = 0;

    private array $attributes = self::DEFAULT_ATTRIBUTES;

    private int $rollUpRows = 0;

    private int $activeChannel = 1;

    private bool $inXds = false;

    private ?int $lastControl = null;

    // "direct" for a change by paint-on or roll-up data, "replace" for EOC and EDM, or null for no change.
    private ?string $displayChange = null;


    protected static function formatOptionsClass(): string
    {
        return SccReadOptions::class;
    }


    protected function read(string $rawSubtitle): Subtitle
    {
        $rawSubtitle = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawLines    = explode(StringHelpers::UNIX_LINE_ENDING, StringHelpers::normalizeEOLs($rawSubtitle));

        $this->channel = $this->formatOptions()->channel;
        $codeLines     = $this->readCodeLines($rawLines, $dropFrame);
        $this->resetDecoder();

        $states = [];
        $frame  = 0;
        foreach ($codeLines as $lineIdx => [$startFrame, $words]) {
            if ($startFrame > $frame) {
                $this->lastControl = null;
            }
            foreach ($words as $wordIdx => $word) {
                $frame = max($frame, $startFrame + $wordIdx);
                $this->decodeWord($word >> 8, $word & 0xFF);
                if ($this->displayChange !== null) {
                    $this->recordState($states, $frame, $lineIdx);
                    $this->displayChange = null;
                }
            }
            $frame = max($frame, $startFrame + count($words));
        }

        $subtitle = new Subtitle();
        if ($dropFrame !== null) {
            $subtitle->setFormatData(self::FORMAT_DATA_KEY, ["dropFrame" => $dropFrame]);
        }
        foreach ($states as $idx => $state) {
            if ($state["lines"] === []) {
                continue;
            }

            $start = $this->frameToSeconds($state["frame"]);
            $end   = isset($states[$idx + 1]) ? $this->frameToSeconds($states[$idx + 1]["frame"]) : $start + $this->options->lastCueDuration;
            if ($end <= $start) {
                continue;
            }

            $cue = new SubtitleCue($start, $end, array_column($state["lines"], "text"));
            $cue->setAlignment($state["lines"][0]["row"] <= 4 ? 8 : null);
            $cue->setFormatData(self::FORMAT_DATA_KEY, [
                "mode"    => $state["mode"],
                "rows"    => array_column($state["lines"], "row"),
                "columns" => array_column($state["lines"], "column"),
            ]);
            $subtitle->addCue($cue, false);
        }

        return $subtitle->reIndexCues();
    }


    /**
     * Converts an SMPTE time code at 29.97 fps to a frame count. A semicolon before the frames marks drop-frame time code,
     * which skips the frame numbers 00 and 01 at the start of each minute except every tenth minute.
     */
    private static function timecodeToFrames(int $hours, int $minutes, int $seconds, int $frames, bool $dropFrame): int
    {
        $count = (($hours * 60 + $minutes) * 60 + $seconds) * 30 + $frames;
        if ($dropFrame) {
            $totalMinutes = $hours * 60 + $minutes;
            $count       -= 2 * ($totalMinutes - intdiv($totalMinutes, 10));
        }

        return $count;
    }


    /**
     * @return list<array{int, list<int>}> the start frame and the 16-bit words of each line, in time order
     */
    private function readCodeLines(array $rawLines, ?bool &$dropFrame): array
    {
        $dropFrame = null;
        $header    = null;
        $codeLines = [];
        foreach ($rawLines as $lineNumber => $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === "") {
                continue;
            }
            if ($header === null) {
                $header = $rawLine;
                if ($header !== self::HEADER) {
                    throw new ParsingException("An SCC file must start with the line \"" . self::HEADER . "\".", $lineNumber + 1);
                }
                continue;
            }

            if (!preg_match("/^(\d{2}):(\d{2}):(\d{2})([:;])(\d{2})(?:\s+(.*))?$/", $rawLine, $matches)) {
                throw new ParsingException("The SCC line does not start with a time code: $rawLine", $lineNumber + 1);
            }

            $lineDropFrame = $matches[4] === ";";
            $dropFrame   ??= $lineDropFrame;
            $words         = [];
            foreach (preg_split("/\s+/", $matches[6] ?? "", -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (!preg_match("/^[0-9a-fA-F]{4}$/", $word)) {
                    throw new ParsingException("The SCC line has the invalid byte pair \"$word\".", $lineNumber + 1);
                }
                $words[] = hexdec($word);
            }

            $start       = self::timecodeToFrames((int) $matches[1], (int) $matches[2], (int) $matches[3], (int) $matches[5], $lineDropFrame);
            $codeLines[] = [$start, $words];
        }

        if ($header === null) {
            throw new ParsingException("An SCC file must start with the line \"" . self::HEADER . "\".");
        }

        // Some writers emit lines out of time order, see https://github.com/pbs/pycaption/issues/352. A decoder plays them in time order.
        usort($codeLines, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $codeLines;
    }


    private function resetDecoder(): void
    {
        $this->displayed     = [];
        $this->nonDisplayed  = [];
        $this->mode          = self::MODE_POP_ON;
        $this->row           = Cea608::ROWS;
        $this->column        = 0;
        $this->attributes    = self::DEFAULT_ATTRIBUTES;
        $this->rollUpRows    = 0;
        $this->activeChannel = 1;
        $this->inXds         = false;
        $this->lastControl   = null;
        $this->displayChange = null;
    }


    /**
     * Applies the parity and redundancy rules of 47 CFR 15.119 (i) and (j) to a byte pair.
     */
    private function decodeWord(int $first, int $second): void
    {
        $code = $first & 0x7F;
        if ($code < 0x10 || $code > 0x1F) {
            $this->lastControl = null;
            $this->decodeCharacterPair($first, $second);

            return;
        }

        $word = ($first << 8) | $second;
        if (!Cea608::hasOddParity($first)) {
            $redundant         = $this->lastControl !== null && ($this->lastControl & 0xFF) === $second;
            $this->lastControl = null;
            if (!$redundant) {
                $this->decodeCharacterPair(0x80, $second);
            }

            return;
        }
        if (!Cea608::hasOddParity($second) || $this->lastControl === $word) {
            $this->lastControl = null;

            return;
        }

        $this->lastControl   = $word;
        $this->inXds         = false;
        $this->activeChannel = ($code & 0x08) === 0 ? 1 : 2;
        if ($this->activeChannel === $this->channel) {
            $this->decodeControl($code & 0x17, $second & 0x7F);
        }
    }


    private function decodeCharacterPair(int $first, int $second): void
    {
        $code = $first & 0x7F;
        if ($code > 0x00 && $code < 0x10 && Cea608::hasOddParity($first)) {
            // Extended data service packets in field 2 run from a start code 0x01 to 0x0E up to the end code 0x0F.
            $this->inXds = $code !== 0x0F;

            return;
        }
        if ($this->inXds || $this->activeChannel !== $this->channel || $this->mode === self::MODE_TEXT) {
            return;
        }

        foreach ([$first, $second] as $byte) {
            // A byte with a parity error shows as a solid block on a television. A text cue drops it, as it drops the block 0x7F.
            $character = Cea608::hasOddParity($byte) ? Cea608::standardCharacter($byte & 0x7F) : null;
            if ($character !== null) {
                $this->writeCharacter($character);
            }
        }
    }


    private function decodeControl(int $first, int $second): void
    {
        if ($first === 0x14 || $first === 0x15) {
            if ($second <= 0x2F) {
                $this->decodeCommand($second);

                return;
            }
        }
        if ($this->mode === self::MODE_TEXT) {
            return;
        }

        $pac = Cea608::decodePac($first, $second);
        if ($pac !== null) {
            $this->applyPac($pac);
        } elseif ($first === 0x11 && $second >= 0x20 && $second <= 0x2F) {
            $midRow           = Cea608::decodeMidRow($second);
            $this->attributes = [
                "color"     => $midRow["color"] ?? $this->attributes["color"],
                "italic"    => $midRow["italic"],
                "underline" => $midRow["underline"],
            ];
            $this->writeCharacter(" ");
        } elseif ($first === 0x11 && $second >= 0x30 && $second <= 0x3F) {
            $this->writeCharacter($second === 0x39 ? null : Cea608::specialCharacter($second));
        } elseif (($first === 0x12 || $first === 0x13) && $second >= 0x20 && $second <= 0x3F) {
            // An extended character replaces the standard character that goes before it for older decoders.
            $this->column = max(0, $this->column - 1);
            $this->writeCharacter(Cea608::extendedCharacter($first, $second));
        } elseif ($first === 0x17 && $second >= 0x21 && $second <= 0x23) {
            $this->column = min(Cea608::COLUMNS - 1, $this->column + $second - 0x20);
        }
    }


    private function decodeCommand(int $command): void
    {
        switch ($command) {
            case Cea608::RESUME_CAPTION_LOADING:
                $this->mode = self::MODE_POP_ON;
                break;
            case Cea608::RESUME_DIRECT_CAPTIONING:
                $this->mode = self::MODE_PAINT_ON;
                break;
            case Cea608::TEXT_RESTART:
            case Cea608::RESUME_TEXT_DISPLAY:
                $this->mode = self::MODE_TEXT;
                break;
            case Cea608::ROLL_UP_2:
            case Cea608::ROLL_UP_3:
            case Cea608::ROLL_UP_4:
                $this->startRollUp($command - Cea608::ROLL_UP_2 + 2);
                break;
            case Cea608::BACKSPACE:
                if ($this->mode !== self::MODE_TEXT && $this->column > 0) {
                    $this->column--;
                    $this->setCell(null);
                }
                break;
            case Cea608::DELETE_TO_END_OF_ROW:
                if ($this->mode !== self::MODE_TEXT) {
                    $memory = &$this->targetMemory();
                    foreach (array_keys($memory[$this->row] ?? []) as $column) {
                        if ($column >= $this->column) {
                            unset($memory[$this->row][$column]);
                        }
                    }
                    $this->markDirectChange();
                }
                break;
            case Cea608::CARRIAGE_RETURN:
                if ($this->mode === self::MODE_ROLL_UP) {
                    $this->rollUp();
                }
                break;
            case Cea608::ERASE_DISPLAYED_MEMORY:
                $this->displayed     = [];
                $this->displayChange = "replace";
                break;
            case Cea608::ERASE_NON_DISPLAYED:
                $this->nonDisplayed = [];
                break;
            case Cea608::END_OF_CAPTION:
                [$this->displayed, $this->nonDisplayed] = [$this->nonDisplayed, $this->displayed];
                $this->mode          = self::MODE_POP_ON;
                $this->displayChange = "replace";
                break;
        }
    }


    private function startRollUp(int $rows): void
    {
        if ($this->mode !== self::MODE_ROLL_UP) {
            $this->displayed     = [];
            $this->nonDisplayed  = [];
            $this->row           = Cea608::ROWS;
            $this->displayChange = "replace";
        }

        $this->mode       = self::MODE_ROLL_UP;
        $this->rollUpRows = $rows;
        $this->column     = 0;
        $this->attributes = self::DEFAULT_ATTRIBUTES;
        foreach (array_keys($this->displayed) as $row) {
            if ($row > $this->row || $row <= $this->row - $rows) {
                unset($this->displayed[$row]);
                $this->markDirectChange();
            }
        }
    }


    private function rollUp(): void
    {
        $rolled = [];
        for ($row = $this->row - $this->rollUpRows + 1; $row < $this->row; $row++) {
            if (isset($this->displayed[$row + 1])) {
                $rolled[$row] = $this->displayed[$row + 1];
            }
        }

        $this->displayed  = $rolled;
        $this->column     = 0;
        $this->attributes = self::DEFAULT_ATTRIBUTES;
        $this->markDirectChange();
    }


    private function applyPac(array $pac): void
    {
        if ($this->mode === self::MODE_ROLL_UP && $pac["row"] !== $this->row && $this->displayed !== []) {
            // The roll-up window moves to the new base row without erasing.
            $moved = [];
            foreach ($this->displayed as $row => $cells) {
                $newRow = $row + $pac["row"] - $this->row;
                if ($newRow >= 1 && $newRow <= Cea608::ROWS) {
                    $moved[$newRow] = $cells;
                }
            }
            $this->displayed = $moved;
            $this->markDirectChange();
        }

        $this->row        = $pac["row"];
        $this->column     = $pac["column"];
        $this->attributes = ["color" => $pac["color"], "italic" => $pac["italic"], "underline" => $pac["underline"]];
    }


    private function writeCharacter(?string $character): void
    {
        if ($this->mode === self::MODE_TEXT) {
            return;
        }

        $this->column = min($this->column, Cea608::COLUMNS - 1);
        $this->setCell($character === null ? null : ["char" => $character] + $this->attributes);
        $this->column++;
    }


    private function setCell(?array $cell): void
    {
        $memory = &$this->targetMemory();
        if ($cell === null) {
            unset($memory[$this->row][$this->column]);
        } else {
            $memory[$this->row][$this->column] = $cell;
        }
        $this->markDirectChange();
    }


    private function &targetMemory(): array
    {
        if ($this->mode === self::MODE_POP_ON) {
            return $this->nonDisplayed;
        }

        return $this->displayed;
    }


    private function markDirectChange(): void
    {
        if ($this->mode !== self::MODE_POP_ON) {
            $this->displayChange ??= "direct";
        }
    }


    /**
     * Adds the displayed captions as a new state. Paint-on and roll-up data on one line of the file give one state.
     */
    private function recordState(array &$states, int $frame, int $lineIdx): void
    {
        $lines = $this->renderDisplayed();
        $last  = $states === [] ? null : $states[count($states) - 1];
        if ($last !== null && $last["lines"] === $lines) {
            return;
        }

        $mode = $this->mode === self::MODE_TEXT ? self::MODE_POP_ON : $this->mode;
        if ($last !== null && $this->displayChange === "direct" && $last["direct"] && $last["line"] === $lineIdx
            && $last["lines"] !== [] && $lines !== []) {
            $states[count($states) - 1]["lines"] = $lines;
            $states[count($states) - 1]["mode"]  = $mode;

            return;
        }

        $states[] = ["frame" => $frame, "line" => $lineIdx, "lines" => $lines, "mode" => $mode, "direct" => $this->displayChange === "direct"];
    }


    /**
     * @return list<array{row: int, column: int, text: string}>
     */
    private function renderDisplayed(): array
    {
        ksort($this->displayed);
        $lines = [];
        foreach ($this->displayed as $row => $cells) {
            $columns = array_keys(array_filter($cells, fn (?array $cell): bool => $cell !== null && trim($cell["char"]) !== ""));
            if ($columns === []) {
                continue;
            }

            $first = min($columns);
            $last  = max($columns);
            $runs  = [];
            for ($column = $first; $column <= $last; $column++) {
                $cell   = $cells[$column] ?? ["char" => " "] + self::DEFAULT_ATTRIBUTES;
                $runs[] = $cell;
            }
            $lines[] = ["row" => $row, "column" => $first, "text" => $this->toMarkup($runs)];
        }

        return $lines;
    }


    /**
     * Writes the cells as core markup. Spaces at the edge of a styled run go outside its tags.
     */
    private function toMarkup(array $cells): string
    {
        $open    = self::DEFAULT_ATTRIBUTES;
        $text    = "";
        $pending = "";
        foreach ($cells as $cell) {
            if ($cell["char"] === " ") {
                $pending .= " ";
                continue;
            }

            $wanted = ["color" => $cell["color"], "italic" => $cell["italic"], "underline" => $cell["underline"]];
            if ($wanted !== $open) {
                $text .= $this->switchTags($open, $wanted, $pending);
                $open  = $wanted;
            } else {
                $text .= $pending;
            }
            $pending = "";
            $text   .= Markup::escapeText($cell["char"]);
        }

        return $text . $this->switchTags($open, self::DEFAULT_ATTRIBUTES, "");
    }


    /**
     * Closes the tags from the first attribute that changes, in the nesting order font, i, u, and opens the new ones.
     */
    private function switchTags(array $from, array $to, string $between): string
    {
        $keys    = ["color", "italic", "underline"];
        $changed = 0;
        while ($changed < 3 && $from[$keys[$changed]] === $to[$keys[$changed]]) {
            $changed++;
        }

        $markup = "";
        for ($idx = 2; $idx >= $changed; $idx--) {
            $markup .= $this->tag($keys[$idx], $from, true);
        }
        $markup .= $between;
        for ($idx = $changed; $idx <= 2; $idx++) {
            $markup .= $this->tag($keys[$idx], $to, false);
        }

        return $markup;
    }


    private function tag(string $key, array $attributes, bool $closing): string
    {
        return match (true) {
            $key === "color" && $attributes["color"] !== Cea608::WHITE => $closing ? "</font>" : "<font color=\"" . Cea608::COLORS[$attributes["color"]] . "\">",
            $key === "italic" && $attributes["italic"]                => $closing ? "</i>" : "<i>",
            $key === "underline" && $attributes["underline"]          => $closing ? "</u>" : "<u>",
            default                                                   => "",
        };
    }


    private function frameToSeconds(int $frame): float
    {
        return $frame * 1001 / 30000;
    }
}
