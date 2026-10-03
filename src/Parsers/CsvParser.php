<?php

namespace SubtitleToolbox\Parsers;

use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Formatters\CsvTimeFormat;
use SubtitleToolbox\FrameRate;
use SubtitleToolbox\Markup;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class CsvParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = "csv";
    public const DELIMITERS      = [",", ";", "\t"];

    private CsvColumns $columns;


    public static function checkDelimiter(mixed $delimiter): void
    {
        if (!in_array($delimiter, self::DELIMITERS, true)) {
            throw new InvalidArgumentException("The CSV delimiter must be \",\", \";\" or a tab.");
        }
    }


    protected static function formatOptionsClass(): string
    {
        return CsvReadOptions::class;
    }


    protected function read(string $rawSubtitle): Subtitle
    {
        $this->columns = $this->formatOptions()->columns ?? new CsvColumns();
        $content       = StringHelpers::removeUtf8Bom($rawSubtitle);
        $delimiter     = $this->formatOptions()->delimiter ?? self::detectDelimiter($content);
        $records       = array_filter(
            self::records($content, $delimiter),
            fn (array $record): bool => array_filter($record[1], fn (string $cell): bool => trim($cell) !== "") !== []
        );

        $header = $this->columns->header && $records !== [] ? array_shift($records)[1] : null;
        $roles  = $this->resolveRoles($header);

        $subtitle   = new Subtitle();
        $frameRate  = $this->columns->frameRate === null ? null : new FrameRate($this->columns->frameRate);
        $timeFormat = null;
        $openEnds   = [];
        foreach (array_values($records) as $rowIndex => [$lineNumber, $cells]) {
            $cell = fn (string $role): string => isset($roles[$role]) ? trim($cells[$roles[$role]] ?? "") : "";
            try {
                $start = self::parseTime($cell("start"), $frameRate, $lineNumber);
                $end   = match (true) {
                    $cell("end") !== ""      => self::parseTime($cell("end"), $frameRate, $lineNumber),
                    $cell("duration") !== "" => $start + self::parseTime($cell("duration"), $frameRate, $lineNumber),
                    default                  => null,
                };
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $rowIndex, [implode($delimiter, $cells)]);
                continue;
            }
            $timeFormat ??= self::timeFormatOf($cell("start"));

            $cue = new SubtitleCue($start, $end ?? $start, $this->lines($cells[$roles["text"]] ?? "", $cell("speaker")));
            if ($cell("identifier") !== "") {
                $cue->setIdentifier($cell("identifier"));
            }
            $others = array_diff_key($cells + array_fill(0, count($header ?? $cells), ""), array_flip($roles));
            if ($others !== []) {
                $named = [];
                foreach ($others as $index => $value) {
                    $named[$header[$index] ?? $index] = $value;
                }
                $cue->setFormatData(self::FORMAT_DATA_KEY, ["columns" => $named]);
            }
            if ($end === null) {
                $openEnds[] = $cue;
            }
            $subtitle->addCue($cue, false);
        }
        $subtitle->reIndexCues();
        $this->closeOpenEnds($subtitle, $openEnds);

        $subtitle->setFormatData(self::FORMAT_DATA_KEY, [
            "delimiter"  => $delimiter,
            "header"     => $header,
            "roles"      => $roles,
            "width"      => max([count($header ?? []), ...array_map("count", array_column($records, 1))]),
            "timeFormat" => $timeFormat ?? CsvTimeFormat::Dot->value,
            "frameRate"  => $this->columns->frameRate,
        ]);

        return $subtitle;
    }


    /**
     * Returns the delimiter that occurs most often in the first record, outside quotes. A comma wins a tie.
     */
    public static function detectDelimiter(string $content): string
    {
        $counts = array_fill_keys(self::DELIMITERS, 0);
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
    public static function records(string $content, string $delimiter): array
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
    public static function parseTime(string $time, ?FrameRate $frameRate, ?int $lineNumber = null): float
    {
        if (preg_match('/^\d+(?:\.\d+)?$/', $time)) {
            return (float) $time;
        }
        if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)(?:([.,:])(\d+))?$/', $time, $matches)) {
            $seconds  = $matches[1] * 3600 + $matches[2] * 60 + (int) $matches[3];
            $fraction = $matches[5] ?? "";
            if (($matches[4] ?? "") !== ":") {
                return $seconds + (float) "0.$fraction";
            }
            if ($frameRate !== null) {
                return $seconds + $frameRate->framesToSeconds((int) $fraction);
            }
        }

        throw new ParsingException(
            substr_count($time, ":") === 3
                ? "The time \"$time\" counts frames. Pass the frame rate in CsvColumns."
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
    private function lines(string $text, string $speaker): array
    {
        $lines = explode("\n", Markup::escapeText($text));
        if ($speaker !== "") {
            foreach ($lines as $index => $line) {
                if (trim($line) !== "") {
                    $lines[$index] = "<v " . Markup::escapeText($speaker) . ">" . ltrim($line);
                    break;
                }
            }
        }

        return $lines;
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
