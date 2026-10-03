<?php

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Options;
use SubtitleToolbox\Parsers\CsvParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

class CsvFormatter extends SubtitleFormatter
{
    public const OPTION_DELIMITER          = "delimiter";
    public const OPTION_TIME_FORMAT        = "timeFormat";
    public const OPTION_FRAME_RATE         = "OPTION_FRAME_RATE";
    public const OPTION_SECOND_TEXT        = "secondText";
    public const OPTION_SECOND_TEXT_HEADER = "secondTextHeader";
    public const OPTION_ESCAPE_FORMULAS    = "escapeFormulas";

    // The old key of OPTION_FRAME_RATE. Callers that pass it as a string keep working.
    private const OPTION_FRAME_RATE_OLD_KEY = "frameRate";

    private const SPEAKER_REGEX = '/^<v(?:\.[^\s>]*)?\s+([^>]*)>/';


    /**
     * Writes one row per cue. A subtitle from CsvParser keeps its columns, header names, delimiter and time format.
     */
    public function format(Subtitle $subtitle, array $options = []): string
    {
        $this->rejectUnknownOptions($options);
        $data      = $subtitle->getFormatData(CsvParser::FORMAT_DATA_KEY);
        $delimiter = $options[self::OPTION_DELIMITER] ?? $data["delimiter"] ?? ",";
        CsvParser::checkDelimiter($delimiter);
        $timeFormat = $options[self::OPTION_TIME_FORMAT] ?? $data["timeFormat"] ?? CsvParser::TIME_DOT;
        if (!in_array($timeFormat, CsvParser::TIME_FORMATS, true)) {
            throw new InvalidArgumentException("The option " . self::OPTION_TIME_FORMAT . " must be one of " .
                                               implode(", ", CsvParser::TIME_FORMATS) . ".");
        }
        $fps       = $options[self::OPTION_FRAME_RATE] ?? $options[self::OPTION_FRAME_RATE_OLD_KEY] ?? $data["frameRate"] ?? null;
        $frameRate = $fps === null ? null : new FrameRate($fps);
        if ($timeFormat === CsvParser::TIME_FRAMES && $frameRate === null) {
            throw new InvalidArgumentException("The time format " . CsvParser::TIME_FRAMES . " needs the option " . self::OPTION_FRAME_RATE . ".");
        }
        $second = $options[self::OPTION_SECOND_TEXT] ?? null;
        if ($second !== null && !$second instanceof Subtitle) {
            throw new InvalidArgumentException("The option " . self::OPTION_SECOND_TEXT . " must be a Subtitle.");
        }
        $escapeFormulas = Options::flag($options, self::OPTION_ESCAPE_FORMULAS) ?? false;

        $cues               = array_values($subtitle->getCues());
        $rows               = array_map($this->splitSpeaker(...), $cues);
        [$columns, $header] = $this->columns($data, $cues, $rows);
        if ($second !== null) {
            $position = array_search(["text", null], $columns, true) + 1;
            array_splice($columns, $position, 0, [["second", null]]);
            array_splice($header, $position, 0, [$options[self::OPTION_SECOND_TEXT_HEADER] ?? "text2"]);
            $secondTexts = $this->secondTexts($cues, array_values($second->getCues()));
        }

        $records = $data === [] || $data["header"] !== null ? [$header] : [];
        foreach ($cues as $index => $cue) {
            $records[] = array_map(fn (array $column): string => match ($column[0]) {
                "identifier" => $cue->getIdentifier() ?? "",
                "start"      => $this->secondsCell($cue->getStart(), $timeFormat, $frameRate),
                "end"        => $this->secondsCell($cue->getEnd(), $timeFormat, $frameRate),
                "duration"   => $this->secondsCell($cue->getEnd() - $cue->getStart(), $timeFormat, $frameRate),
                "speaker"    => $rows[$index][0],
                "text"       => $rows[$index][1],
                "second"     => $secondTexts[$index],
                "other"      => (string) ($cue->getFormatData(CsvParser::FORMAT_DATA_KEY)["columns"][$column[1]] ?? ""),
            }, $columns);
        }

        $lineEnding = $options[self::OPTION_LINE_ENDING] ?? "\n";
        // Validates the line ending. In-cell line breaks stay LF, as in Excel, so the records get the line ending here.
        $this->applyOutputOptions("", [self::OPTION_LINE_ENDING => $lineEnding]);
        $lines = array_map(fn (array $record): string => implode($delimiter, array_map(
            fn (string $cell): string => $this->quote($escapeFormulas ? $this->escapeFormula($cell) : $cell, $delimiter),
            $record
        )), $records);

        return $this->applyOutputOptions(implode($lineEnding, $lines) . $lineEnding, [
            self::OPTION_BOM => $options[self::OPTION_BOM] ?? true,
        ]);
    }


    /**
     * @param list<SubtitleCue>           $cues
     * @param list<array{string, string}> $rows
     *
     * @return array{list<array{string, string|int|null}>, list<string>}
     */
    private function columns(array $data, array $cues, array $rows): array
    {
        $columns = [];
        $header  = [];
        if ($data !== []) {
            for ($index = 0; $index < $data["width"]; $index++) {
                $role      = array_search($index, $data["roles"], true);
                $columns[] = $role === false ? ["other", $data["header"][$index] ?? $index] : [$role, null];
                $header[]  = $data["header"][$index] ?? "";
            }
        } else {
            $hasIdentifier = array_filter($cues, fn (SubtitleCue $cue): bool => $cue->getIdentifier() !== null) !== [];
            foreach (["identifier", "start", "end", "text"] as $role) {
                if ($role !== "identifier" || $hasIdentifier) {
                    $columns[] = [$role, null];
                    $header[]  = $role;
                }
            }
            $others = [];
            foreach ($cues as $cue) {
                $others += $cue->getFormatData(CsvParser::FORMAT_DATA_KEY)["columns"] ?? [];
            }
            foreach (array_keys($others) as $name) {
                $columns[] = ["other", $name];
                $header[]  = (string) $name;
            }
        }

        $hasSpeaker = array_filter($rows, fn (array $row): bool => $row[0] !== "") !== [];
        if ($hasSpeaker && !in_array(["speaker", null], $columns, true)) {
            $position = array_search(["text", null], $columns, true);
            array_splice($columns, $position, 0, [["speaker", null]]);
            array_splice($header, $position, 0, ["speaker"]);
        }

        return [$columns, $header];
    }


    /**
     * Returns the speaker of the leading <v> tag and the plain text of the cue.
     *
     * @return array{string, string}
     */
    private function splitSpeaker(SubtitleCue $cue): array
    {
        $lines   = $cue->getLines();
        $speaker = "";
        if ($lines !== [] && preg_match(self::SPEAKER_REGEX, $lines[0], $matches)) {
            $speaker  = trim(Markup::decodeEntities($matches[1]));
            $lines[0] = substr($lines[0], strlen($matches[0]));
        }

        return [$speaker, implode("\n", Markup::plainLines($lines))];
    }


    /**
     * Gives each row the text of the second cue that overlaps it most. A second cue fills only the first row that it wins.
     *
     * @param list<SubtitleCue> $cues
     * @param list<SubtitleCue> $secondCues
     *
     * @return list<string>
     */
    private function secondTexts(array $cues, array $secondCues): array
    {
        $texts = [];
        $used  = [];
        foreach ($cues as $cue) {
            $best        = null;
            $bestOverlap = 0.0;
            foreach ($secondCues as $index => $secondCue) {
                $overlap = min($cue->getEnd(), $secondCue->getEnd()) - max($cue->getStart(), $secondCue->getStart());
                if ($overlap > $bestOverlap) {
                    $best        = $index;
                    $bestOverlap = $overlap;
                }
            }
            if ($best === null || isset($used[$best])) {
                $texts[] = "";
                continue;
            }
            $used[$best] = true;
            $texts[]     = implode("\n", Markup::plainLines($secondCues[$best]->getLines()));
        }

        return $texts;
    }


    private function secondsCell(float $seconds, string $layout, ?FrameRate $frameRate): string
    {
        $seconds      = max(0, $seconds);
        $milliseconds = Timecode::totalMilliseconds($seconds);

        return match ($layout) {
            CsvParser::TIME_SECONDS => rtrim(rtrim(sprintf("%d.%03d", intdiv($milliseconds, 1000), $milliseconds % 1000), "0"), "."),
            CsvParser::TIME_DOT     => sprintf("%02d:%02d:%02d.%03d", ...Timecode::milliseconds($seconds)),
            CsvParser::TIME_COMMA   => sprintf("%02d:%02d:%02d,%03d", ...Timecode::milliseconds($seconds)),
            CsvParser::TIME_FRAMES  => sprintf("%02d:%02d:%02d:%02d", ...Timecode::clockSecondsAndFrames($seconds, $frameRate)),
        };
    }


    private function escapeFormula(string $cell): string
    {
        return $cell !== "" && str_contains("=+-@", $cell[0]) ? "'$cell" : $cell;
    }


    private function quote(string $cell, string $delimiter): string
    {
        return strpbrk($cell, "\"\r\n$delimiter") === false ? $cell : '"' . str_replace('"', '""', $cell) . '"';
    }
}
