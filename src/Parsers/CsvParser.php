<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\FormatDataSchema;
use SubtitleToolbox\Formatters\Options\CsvTimeFormat;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Parsers\Options\CsvColumns;
use SubtitleToolbox\Parsers\Options\CsvReadOptions;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

final class CsvParser extends SubtitleParser
{
    protected const FORMAT_OPTIONS = CsvReadOptions::class;
    public const FORMAT_DATA_KEY = Format::Csv->value;

    /** Header names, without case, spaces, underscores and hyphens, that stand for a role when no header has the role name. */
    private const SYNONYMS = [
        "start"    => ["begin", "in", "starttime", "starttc", "timecode", "tcin"],
        "end"      => ["out", "stop", "endtime", "endtc", "tcout"],
        "duration" => ["length"],
        "speaker"  => ["name", "character"],
        "text"     => ["subtitle", "caption", "dialogue", "transcript"],
    ];

    private CsvColumns $columns;


    protected function read(string $content): Subtitle
    {
        $this->columns = $this->formatOptions()->columns ?? new CsvColumns();
        $delimiter     = $this->formatOptions()->delimiter ?? self::detectDelimiter($content);
        $records       = array_values(array_filter(
            $this->records($content, $delimiter),
            fn (array $record): bool => array_filter($record[1], fn (string $cell): bool => trim($cell) !== "") !== []
        ));
        self::checkWidth($records);
        if ($this->columns->header && $this->options->lenient) {
            $records = $this->skipRowsBeforeHeader($records, $delimiter);
        }

        [$headerLine, $header] = $this->columns->header && $records !== [] ? array_shift($records) : [null, null];
        $roles                 = $this->resolveRoles($header);
        $this->warnSynonymColumns($header, $roles, $headerLine, $delimiter);

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
     * @param array<int, array{int, list<string>}> $records
     */
    private static function checkWidth(array $records): void
    {
        foreach ($records as [$lineNumber, $cells]) {
            if (count($cells) > FormatDataSchema::CSV_MAX_COLUMNS) {
                throw new ParsingException("The table has " . count($cells) . " columns. The limit is " . FormatDataSchema::CSV_MAX_COLUMNS . ".", $lineNumber);
            }
        }
    }


    /**
     * Returns the delimiter that occurs most often in the first line that has one, outside quotes. A comma wins a tie.
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
            } elseif (!$quoted && ($char === "\n" || $char === "\r") && max($counts) > 0) {
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
     * In lenient mode, a quote without a closing quote ends at the end of its line.
     *
     * @return list<array{int, list<string>}>
     */
    private function records(string $content, string $delimiter): array
    {
        $records = [];
        $cells   = [];
        $cell    = "";
        $line    = 1;
        $start   = 1;
        $quoted  = false;
        $quoteAt = 0;
        $length  = strlen($content);
        for ($i = 0; ; $i = $lineEnd) {
            for (; $i < $length; $i++) {
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
                    [$quoted, $quoteAt, $quoteLine] = [true, $i, $line];
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
            if (!$quoted) {
                break;
            }
            if (!$this->options->lenient) {
                throw new ParsingException("A quoted CSV cell has no closing quote.", $start);
            }
            $lineStart = $quoteAt - strcspn(strrev(substr($content, 0, $quoteAt)), "\r\n");
            $lineEnd   = $quoteAt + 1 + strcspn($content, "\r\n", $quoteAt + 1);
            $cell      = substr($content, $quoteAt + 1, $lineEnd - $quoteAt - 1);
            $quoted    = false;
            $line      = $quoteLine;
            $this->warn(
                "A quoted CSV cell has no closing quote. The cell ends at the end of the line.",
                $line,
                null,
                [substr($content, $lineStart, $lineEnd - $lineStart)],
                ParseWarningAction::Repaired
            );
        }
        if ($cell !== "" || $cells !== []) {
            $records[] = [$start, [...$cells, $cell]];
        }

        return $records;
    }


    /**
     * Drops the rows before the first row that has the columns of the header, and warns once for them.
     * Without such a row, it keeps all rows, so that resolveRoles() names the missing column.
     *
     * @param list<array{int, list<string>}> $records
     *
     * @return list<array{int, list<string>}>
     */
    private function skipRowsBeforeHeader(array $records, string $delimiter): array
    {
        foreach ($records as $index => [, $cells]) {
            try {
                $this->resolveRoles($cells);
            } catch (ParsingException) {
                continue;
            }
            if ($index > 0) {
                $this->warn(
                    "The table has " . ($index === 1 ? "1 row" : "$index rows") . " before the header row.",
                    $records[0][0],
                    null,
                    array_map(fn (array $record): string => implode($delimiter, $record[1]), array_slice($records, 0, $index)),
                    ParseWarningAction::Skipped
                );
            }

            return array_slice($records, $index);
        }

        return $records;
    }


    /**
     * Reads seconds, hh:mm:ss.mmm, hh:mm:ss,mmm or hh:mm:ss:ff. Frames need a frame rate.
     */
    private static function parseTime(string $time, ?FrameRate $frameRate, int $lineNumber): float
    {
        if (preg_match('/^\d+(?:\.\d+)?$/', $time)) {
            return self::boundedTime((float) $time, $time, $lineNumber);
        }
        if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)(?:([.,:])(\d+))?$/', $time, $matches)) {
            [$hours, $minutes, $seconds] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
            $fraction                    = $matches[5] ?? "";
            if (($matches[4] ?? "") !== ":") {
                return self::boundedTime(Timecode::toSeconds($hours, $minutes, $seconds, $fraction), $time, $lineNumber);
            }
            if ($frameRate !== null) {
                return self::boundedTime(Timecode::toSecondsFromFrames($hours, $minutes, $seconds, (int) $fraction, $frameRate), $time, $lineNumber);
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
     * Returns the index of the first header name that is a synonym of $role, or false.
     * The match ignores case, spaces, underscores and hyphens. An earlier synonym in SYNONYMS wins.
     *
     * @param list<string> $header
     */
    private static function findSynonymColumn(string $role, array $header): int|false
    {
        $names = array_map(fn (string $name): string => (string) preg_replace('/[\s_-]+/', "", strtolower($name)), $header);
        foreach (self::SYNONYMS[$role] ?? [] as $synonym) {
            $index = array_search($synonym, $names, true);
            if ($index !== false) {
                return $index;
            }
        }

        return false;
    }


    /**
     * Records a ParseWarning that names the columns that resolveRoles() found by a synonym.
     *
     * @param list<string>|null  $header
     * @param array<string, int> $roles
     */
    private function warnSynonymColumns(?array $header, array $roles, ?int $headerLine, string $delimiter): void
    {
        if ($header === null || $this->formatOptions()->columns !== null) {
            return;
        }
        $found = [];
        foreach ($roles as $role => $index) {
            if (strtolower(trim($header[$index])) !== $role) {
                $found[] = "\"" . trim($header[$index]) . "\" as $role";
            }
        }
        if ($found !== []) {
            $last = array_pop($found);
            $this->warn(
                "The parser reads the " . ($found === [] ? "column $last" : "columns " . implode(", ", $found) . " and $last") . ".",
                $headerLine,
                null,
                [implode($delimiter, $header)],
                ParseWarningAction::Repaired
            );
        }
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
            if ($index === false && $this->formatOptions()->columns === null) {
                $index = self::findSynonymColumn($role, $header ?? []);
            }
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
