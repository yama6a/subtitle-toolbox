<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class CsvParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = CsvReadOptions::class;
    public const FORMAT_DATA_KEY = Format::Csv->value;

    private CsvColumns $columns;


    protected function read(string $rawSubtitle): Subtitle
    {
        $this->columns = $this->formatOptions()->columns ?? new CsvColumns();
        $delimiter     = $this->formatOptions()->delimiter ?? self::detectDelimiter($rawSubtitle);
        $records       = array_filter(
            self::records($rawSubtitle, $delimiter),
            fn (array $record): bool => array_filter($record[1], fn (string $cell): bool => trim($cell) !== "") !== []
        );

        $header = $this->columns->header && $records !== [] ? array_shift($records)[1] : null;
        $roles  = $this->resolveRoles($header);

        $subtitle   = new Subtitle();
        $parsedCues = [];
        $rate       = $this->formatOptions()->frameRate;
        $frameRate  = $rate === null ? null : new FrameRate($rate);
        $timeFormat = null;
        $openEnds   = [];
        foreach (array_values($records) as $rowIndex => [$lineNumber, $cells]) {
            $cell = fn (string $role): string => isset($roles[$role]) ? trim($cells[$roles[$role]] ?? "") : "";
            try {
                [$start, $end] = self::readTimes($cell, $frameRate, $lineNumber);
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $rowIndex, [implode($delimiter, $cells)]);
                continue;
            }
            $timeFormat ??= self::timeFormatOf($cell("start"));

            $cue = new SubtitleCue($start, $end ?? $start, $this->textLines($cells[$roles["text"]] ?? "", $cell("speaker")));
            self::addCellData($cue, $cell, $cells, $roles, $header);
            if ($end === null) {
                $openEnds[] = $cue;
            }
            $parsedCues[] = $cue;
        }
        $subtitle->addCues($parsedCues);
        $this->closeOpenEnds($subtitle, $openEnds);

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, [
            "delimiter"  => $delimiter,
            "header"     => $header,
            "roles"      => $roles,
            "width"      => max([count($header ?? []), ...array_map("count", array_column($records, 1))]),
            "timeFormat" => $timeFormat ?? CsvTimeFormat::Dot->value,
            "frameRate"  => $rate,
        ]);

        return $subtitle;
    }


    /**
     * Returns the start and the end of a record. The end is null when the record has neither an end nor a duration.
     *
     * @param \Closure(string): string $cell the trimmed cell of a role
     * @return array{float, ?float}
     */
    private static function readTimes(\Closure $cell, ?FrameRate $frameRate, int $lineNumber): array
    {
        $start = self::parseTime($cell("start"), $frameRate, $lineNumber);
        $end   = match (true) {
            $cell("end") !== ""      => self::parseTime($cell("end"), $frameRate, $lineNumber),
            $cell("duration") !== "" => $start + self::parseTime($cell("duration"), $frameRate, $lineNumber),
            default                  => null,
        };

        return [$start, $end];
    }


    /**
     * Sets the identifier of $cue, and keeps the cells without a role as format data.
     * The format data keys them by their header name, else by their column index.
     *
     * @param \Closure(string): string $cell
     * @param list<string>             $cells
     * @param array<string, int>       $roles
     * @param list<string>|null        $header
     */
    private static function addCellData(SubtitleCue $cue, \Closure $cell, array $cells, array $roles, ?array $header): void
    {
        if ($cell("identifier") !== "") {
            $cue->setIdentifier($cell("identifier"));
        }
        $named = [];
        foreach (array_diff_key($cells + array_fill(0, count($header ?? $cells), ""), array_flip($roles)) as $index => $value) {
            $named[$header[$index] ?? $index] = $value;
        }
        if ($named !== []) {
            $cue->setFormatData(self::FORMAT_DATA_KEY, ["columns" => $named]);
        }
    }


    /**
     * Returns the delimiter that occurs most often in the first record, outside quotes. A comma wins a tie.
     */
    private static function detectDelimiter(string $content): string
    {
        $counts = array_fill_keys(CsvReadOptions::DELIMITERS, 0);
        $quoted = false;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($char === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && ($char === "\n" || $char === "\r")) {
                break;
            } elseif (!$quoted && isset($counts[$char])) {
                $counts[$char]++;
            }
        }

        return array_search(max($counts), $counts, true);
    }


    /**
     * Splits RFC 4180 content into records of cells, keyed by order, each with the 1-based line number of its start.
     * Line breaks inside quotes become LF. A quote inside an unquoted cell stays text.
     *
     * @return list<array{int, list<string>}>
     */
    private static function records(string $content, string $delimiter): array
    {
        $records = [];
        $cells   = [];
        $cell    = "";
        $line    = 1;
        $start   = 1;
        $quoted  = false;
        $length  = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($quoted) {
                if ($char === '"' && ($content[$i + 1] ?? "") === '"') {
                    $cell .= '"';
                    $i++;
                } elseif ($char === '"') {
                    $quoted = false;
                } elseif ($char === "\r" || $char === "\n") {
                    $i    += $char === "\r" && ($content[$i + 1] ?? "") === "\n" ? 1 : 0;
                    $cell .= "\n";
                    $line++;
                } else {
                    $cell .= $char;
                }
            } elseif ($char === '"' && $cell === "") {
                $quoted = true;
            } elseif ($char === $delimiter) {
                $cells[] = $cell;
                $cell    = "";
            } elseif ($char === "\r" || $char === "\n") {
                $i        += $char === "\r" && ($content[$i + 1] ?? "") === "\n" ? 1 : 0;
                $cells[]   = $cell;
                $records[] = [$start, $cells];
                $cells     = [];
                $cell      = "";
                $start     = ++$line;
            } else {
                $cell .= $char;
            }
        }
        if ($quoted) {
            throw new ParsingException("A quoted CSV cell has no closing quote.", $start);
        }
        if ($cell !== "" || $cells !== []) {
            $records[] = [$start, [...$cells, $cell]];
        }

        return $records;
    }


    /**
     * Reads seconds, hh:mm:ss.mmm, hh:mm:ss,mmm or hh:mm:ss:ff. Frames need a frame rate.
     */
    private static function parseTime(string $time, ?FrameRate $frameRate, int $lineNumber): float
    {
        if (preg_match('/^\d+(?:\.\d+)?$/', $time)) {
            return (float) $time;
        }
        if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)(?:([.,:])(\d+))?$/', $time, $matches)) {
            [$hours, $minutes, $seconds] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
            $fraction                    = $matches[5] ?? "";
            if (($matches[4] ?? "") !== ":") {
                return Timecode::toSeconds($hours, $minutes, $seconds, $fraction);
            }
            if ($frameRate !== null) {
                return Timecode::toSecondsFromFrames($hours, $minutes, $seconds, (int) $fraction, $frameRate);
            }
        }

        throw new ParsingException(
            substr_count($time, ":") === 3
                ? "The time \"$time\" counts frames. Set CsvReadOptions::\$frameRate."
                : "The time \"$time\" is not seconds, hh:mm:ss.mmm, hh:mm:ss,mmm or hh:mm:ss:ff.",
            $lineNumber
        );
    }


    private static function timeFormatOf(string $time): string
    {
        return match (true) {
            str_contains($time, ",")       => CsvTimeFormat::Comma->value,
            substr_count($time, ":") === 3 => CsvTimeFormat::Frames->value,
            str_contains($time, ":")       => CsvTimeFormat::Dot->value,
            default                        => CsvTimeFormat::Seconds->value,
        };
    }


    /**
     * Returns the 0-based column index of each role that the table has.
     *
     * @param list<string>|null $header
     *
     * @return array<string, int>
     */
    private function resolveRoles(?array $header): array
    {
        $names = array_map(fn (string $name): string => strtolower(trim($name)), $header ?? []);
        $roles = [];
        foreach (CsvColumns::ROLES as $role) {
            $column = $this->columns->$role;
            $index  = match (true) {
                is_int($column)    => $column,
                is_string($column) => array_search(strtolower(trim($column)), $names, true),
                default            => array_search($role, $names, true),
            };
            if ($index !== false && ($header === null || $index < count($header))) {
                $roles[$role] = $index;
            } elseif ($column !== null || $role === "start" || $role === "text") {
                throw new ParsingException("The table has no column \"" . ($column ?? $role) . "\" for $role.", 1);
            }
        }

        return $roles;
    }


    /**
     * @return list<string>
     */
    private function textLines(string $text, string $speaker): array
    {
        return Markup::addSpeaker(explode("\n", Markup::escapeText($text)), $speaker);
    }


    /**
     * @param list<SubtitleCue> $openEnds
     */
    private function closeOpenEnds(Subtitle $subtitle, array $openEnds): void
    {
        $starts = array_values(array_unique(array_map(fn (SubtitleCue $cue): float => $cue->getStart(), $subtitle->getCues())));
        sort($starts);
        foreach ($openEnds as $cue) {
            $next = null;
            foreach ($starts as $start) {
                if ($start > $cue->getStart()) {
                    $next = $start;
                    break;
                }
            }
            $cue->setEnd($next ?? $cue->getStart() + $this->options->lastCueDuration);
        }
    }
}
