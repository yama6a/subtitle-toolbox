<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use SubtitleToolbox\CommentAnchors;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class WebVttParser extends SubtitleParser
{
    public const FORMAT_DATA_KEY = Format::WebVtt->value;

    /** @internal */
    public const CUE_SETTINGS    = ["vertical", "line", "position", "size", "align", "region"];
    /** @internal */
    public const REGION_SETTINGS = ["id", "width", "lines", "regionanchor", "viewportanchor", "scroll"];

    private const TIMESTAMP_PATTERN = "((\d{2,3}):)?([0-5]\d):([0-5]\d)\.(\d{3})";

    private const ENTITIES = ["&nbsp;" => "\u{00A0}", "&lrm;" => "\u{200E}", "&rlm;" => "\u{200F}"];


    protected function read(string $rawSubtitle): Subtitle
    {
        $this->warnings = [];
        $rawSubtitle    = StringHelpers::removeUtf8Bom($rawSubtitle);
        $rawSubtitle    = StringHelpers::normalizeEOLs($rawSubtitle);
        $leadingLines   = substr_count(substr($rawSubtitle, 0, strlen($rawSubtitle) - strlen(ltrim($rawSubtitle))), "\n");
        $rawSubtitle    = trim($rawSubtitle);

        if (!str_starts_with($rawSubtitle, "WEBVTT")) {
            throw new ParsingException("The file doesn't start with the string WEBVTT!");
        }

        $lines      = array_merge(array_fill(0, $leadingLines, ""), explode(LineEnding::Lf->value, $rawSubtitle));
        $subtitle   = new Subtitle();
        $parsedCues = [];
        $comments   = [];
        $fileData   = [];
        $seenCue    = false;
        $count      = 0;
        foreach ($this->numberedBlocks($lines) as $lineNumber => $rawLines) {
            $idx = $count++;
            if ($idx === 0) {
                $fileData = $this->parseHeader($rawLines);
                continue;
            }

            $firstLine = trim($rawLines[0]);
            try {
                switch (true) {
                    case str_contains($rawLines[0], "-->") || str_contains($rawLines[1] ?? "", "-->"):
                        $parsedCues[] = $this->parseCueBlock($rawLines, $idx);
                        $seenCue = true;
                        break;
                    case $this->startsWithKeyword($firstLine, "NOTE"):
                        $comments[] = [$this->parseComment($rawLines), count($parsedCues)];
                        break;
                    case !$seenCue && $firstLine === "STYLE":
                        $fileData["styles"][] = implode(LineEnding::Lf->value, array_slice($rawLines, 1));
                        break;
                    case !$seenCue && $firstLine === "REGION":
                        $fileData["regions"][] = $this->parseSettings(
                            implode(" ", array_slice($rawLines, 1)),
                            self::REGION_SETTINGS
                        );
                        break;
                    case preg_match("/^(NOTE|STYLE|REGION)/i", $firstLine) === 1:
                        // The spec parser ignores every block that is not a cue, so these blocks do not throw.
                        break;
                    default:
                        throw new ParsingException("Block #$idx doesn't match anything that we can parse as a WebVTT cue!");
                }
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber, $idx, $rawLines);
            }
        }

        return CommentAnchors::addParsed($subtitle, $parsedCues, $comments)->setFormatData(self::FORMAT_DATA_KEY, $fileData);
    }


    /**
     * Splits at empty lines, and before a timing line that cannot belong to the current cue.
     *
     * @see https://www.w3.org/TR/webvtt1/#collect-a-webvtt-block
     *
     * @internal
     */
    public function splitIntoBlocks(iterable $lines): Generator
    {
        foreach ($this->collectBlocks($lines, false) as [, $block]) {
            yield $block;
        }
    }


    /**
     * Yields the blocks of splitIntoBlocks(), keyed by the 1-based number of their first line.
     * In lenient mode, it splits the cues off a header block that has no empty line after it, and warns.
     *
     * @param iterable<int, string> $lines keyed by the 0-based line number
     *
     * @return Generator<int, list<string>>
     *
     * @internal
     */
    public function numberedBlocks(iterable $lines): Generator
    {
        $isHeader = true;
        foreach ($this->collectBlocks($lines, false) as [$lineNumber, $block]) {
            $timingOffset = $isHeader && $this->lenient ? $this->firstTimingLineOffset($block) : null;
            $isHeader     = false;
            if ($timingOffset === null) {
                yield $lineNumber => $block;
                continue;
            }

            $this->warn(
                "No empty line found after the first line containing WEBVTT! The parser split the header block at line " .
                ($lineNumber + $timingOffset) . ".",
                $lineNumber + $timingOffset,
                0,
                $block,
                ParseWarningAction::Repaired
            );
            yield $lineNumber => array_slice($block, 0, $timingOffset);

            $rest = array_combine(
                range($lineNumber - 1 + $timingOffset, $lineNumber - 2 + count($block)),
                array_slice($block, $timingOffset)
            );
            foreach ($this->collectBlocks($rest, true) as [$restLineNumber, $restBlock]) {
                yield $restLineNumber => $restBlock;
            }
        }
    }


    private function firstTimingLineOffset(array $block): ?int
    {
        foreach (array_slice($block, 1, null, true) as $offset => $line) {
            if (str_contains($line, "-->")) {
                return $offset;
            }
        }

        return null;
    }


    /**
     * @return Generator<int, array{int, list<string>}>
     */
    private function collectBlocks(iterable $lines, bool $hasBlocks): Generator
    {
        $current   = [];
        $startLine = 1;
        foreach ($lines as $index => $line) {
            if (trim($line) === "") {
                if ($current !== []) {
                    yield [$startLine, $current];
                    $hasBlocks = true;
                }
                $current = [];
                continue;
            }

            $startsCue = count($current) === 0
                         || (count($current) === 1 && !str_contains($current[0], "-->"));
            // The header block is exempt, so that a missing empty line after WEBVTT still throws.
            if (str_contains($line, "-->") && !$startsCue && $hasBlocks) {
                yield [$startLine, $current];
                $current = [];
            }
            if ($current === []) {
                $startLine = $index + 1;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            yield [$startLine, $current];
        }
    }


    /**
     * Returns the file format data of the header block that starts with WEBVTT.
     *
     * @internal
     */
    public function parseHeader(array $rawLines): array
    {
        $fileData   = [];
        $headerText = trim(substr($rawLines[0], 6));
        if ($headerText !== "") {
            $fileData["header"] = $headerText;
        }

        $headerLines = array_slice($rawLines, 1);
        foreach ($headerLines as $line) {
            if (str_contains($line, "-->")) {
                throw new ParsingException("No empty line found after the first line containing WEBVTT!");
            }
        }
        if ($headerLines !== []) {
            $fileData["headerLines"] = $headerLines;
        }

        return $fileData;
    }


    /**
     * Parses one cue block as splitIntoBlocks() returns it.
     *
     * @internal
     */
    public function parseCueBlock(array $rawLines, int $index): SubtitleCue
    {
        return $this->parseCue($this->cleanLines($rawLines), $index);
    }


    private function cleanLines(array $rawLines): array
    {
        return array_map(fn (string $line): string => trim(StringHelpers::normalizeSpaces($line)), $rawLines);
    }


    private function parseCue(array $rawLines, int $index): SubtitleCue
    {
        if (str_contains($rawLines[1] ?? "", "-->")) {
            $identifier = $rawLines[0];
            $rawLines   = array_slice($rawLines, 1);
        }
        if (count($rawLines) < 2) {
            throw new ParsingException("Block #$index doesn't have any text lines!");
        }

        $times = explode("-->", $rawLines[0], 2);
        $end   = trim($times[1]);
        if (!preg_match("/^(" . self::TIMESTAMP_PATTERN . ")([ \t]+(.*))?$/", $end, $matches)) {
            throw new ParsingException("The time-string of at least one cue could not be parsed: $end");
        }

        $lines = str_replace(array_keys(self::ENTITIES), array_values(self::ENTITIES), array_slice($rawLines, 1));
        $cue   = new SubtitleCue(
            $this->timeStringToMilliseconds($times[0]),
            $this->timeStringToMilliseconds($matches[1]),
            $lines
        );
        $cue->setIdentifier($identifier ?? null);

        $settings = $this->parseSettings($matches[7] ?? "", self::CUE_SETTINGS);
        $cue->setFormatData(self::FORMAT_DATA_KEY, $settings);
        $cue->setAlignment($this->settingsToAlignment($settings));

        return $cue;
    }


    private function timeStringToMilliseconds(string $timeString): float
    {
        $timeString = trim($timeString);
        if (!preg_match("/^" . self::TIMESTAMP_PATTERN . "$/", $timeString, $matches)) {
            throw new ParsingException("The time-string of at least one cue could not be parsed: $timeString");
        }

        $hours   = (int) $matches[2];
        $minutes = (int) $matches[3];
        $seconds = (int) $matches[4];
        $millis  = (int) $matches[5];

        return $hours * 3600 + $minutes * 60 + $seconds + $millis / 1000;
    }


    /**
     * Keeps the exact value of each known "name:value" token. The last token with the same name wins.
     *
     * @see https://www.w3.org/TR/webvtt1/#parse-the-webvtt-cue-settings
     *
     * @internal
     */
    public function parseSettings(string $input, array $knownNames): array
    {
        $settings = [];
        foreach (preg_split("/[ \t]+/", trim($input), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $colon = strpos($token, ":");
            if ($colon === false || $colon === 0 || $colon === strlen($token) - 1) {
                continue;
            }

            $name = substr($token, 0, $colon);
            if (in_array($name, $knownNames, true)) {
                $settings[$name] = substr($token, $colon + 1);
            }
        }

        return $settings;
    }


    /**
     * Maps only the values with an unambiguous screen position. Start and end depend on the text direction.
     */
    private function settingsToAlignment(array $settings): ?int
    {
        if ($settings === [] || isset($settings["vertical"])) {
            return null;
        }

        $row = match ($settings["line"] ?? null) {
            null, "-1", "100%,end"  => 0,
            "50%,center"            => 3,
            "0", "0%", "0,start"    => 6,
            default                 => null,
        };
        $column = match ($settings["align"] ?? null) {
            "left"           => 1,
            null, "center"   => 2,
            "right"          => 3,
            default          => null,
        };
        if ($row === null || $column === null || !isset($settings["line"]) && !isset($settings["align"])) {
            return null;
        }

        return $row + $column;
    }


    private function parseComment(array $rawLines): string
    {
        $rawLines[0] = substr(trim($rawLines[0]), 4);
        $lines       = array_filter(array_map("trim", $rawLines), fn (string $line): bool => $line !== "");

        return implode(LineEnding::Lf->value, $lines);
    }


    /**
     * @see https://www.w3.org/TR/webvtt1/#webvtt-comment-block
     */
    private function startsWithKeyword(string $line, string $keyword): bool
    {
        return $line === $keyword || preg_match("/^{$keyword}[ \t]/", $line) === 1;
    }
}
