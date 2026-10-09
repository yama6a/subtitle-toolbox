<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use JsonException;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Markup;
use SubtitleToolbox\OptionChecks;
use SubtitleToolbox\Parsers\Options\FormatReadOptions;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;
use SubtitleToolbox\Timecode;

/**
 * The base class of the parsers of this library. Only the library extends it.
 * Its protected members are not API and can change in any release.
 */
abstract class SubtitleParser
{
    /**
     * The FormatReadOptions class that this parser reads from ReadOptions::$format, or null for none.
     *
     * @var class-string<FormatReadOptions>|null
     */
    protected const FORMAT_OPTIONS = null;

    // parse() strips the UTF-8 BOM of a text format only.
    protected const BINARY = false;

    // Formatters split cue times into integer milliseconds, which overflow far above this bound.
    protected const MAX_HOURS = 100000;

    protected ReadOptions $options;

    private ?FormatReadOptions $formatOptions = null;

    /** @var list<ParseWarning> */
    protected array $warnings = [];


    /**
     * Reads $content, which must be UTF-8 for a text format.
     * In lenient mode, Subtitle::getParseWarnings() returns what the parser skipped or repaired.
     */
    final public function parse(string $content, ?ReadOptions $options = null): Subtitle
    {
        $options ??= new ReadOptions();
        $this->useOptions($options);

        $content = static::BINARY ? $content : StringHelpers::removeUtf8Bom($content);

        return $this->read($content)->setParseWarnings($this->warnings);
    }


    abstract protected function read(string $content): Subtitle;


    /**
     * Returns true for a parser of a binary format, which reads bytes rather than text.
     *
     * @internal
     */
    final public static function readsBinary(): bool
    {
        return static::BINARY;
    }


    /**
     * Returns ReadOptions::$format, or the defaults of FORMAT_OPTIONS when it is null.
     * useOptions() builds the defaults once per read.
     */
    protected function formatOptions(): FormatReadOptions
    {
        return $this->formatOptions;
    }


    /**
     * Sets the options for the next read and clears the warnings.
     *
     * @internal
     */
    public function useOptions(ReadOptions $options): static
    {
        $class = static::FORMAT_OPTIONS;
        if ($options->format !== null && ($class === null || !$options->format instanceof $class)) {
            $parser = $this->shortName(static::class);
            $given  = $this->shortName($options->format::class);
            throw new InvalidArgumentException($class === null
                ? "$parser takes no format options, got $given."
                : "$parser takes " . $this->shortName($class) . ", got $given.");
        }

        $this->options       = $options;
        $this->formatOptions = $options->format ?? ($class === null ? null : new $class());
        $this->warnings      = [];

        return $this;
    }


    /**
     * Returns the warnings of the last read in lenient mode.
     *
     * @return list<ParseWarning>
     *
     * @internal
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }


    /**
     * Throws $exception in strict mode, and records that the parser skipped the block in lenient mode.
     *
     * @param list<string> $block
     */
    protected function fail(ParsingException $exception, ?int $lineNumber, ?int $blockIndex, array $block): void
    {
        if (!$this->options->lenient) {
            throw $exception;
        }

        $this->warnings[] = ParseWarning::skipped($exception, $lineNumber, $blockIndex, $block);
    }


    /**
     * @param list<string> $block
     */
    protected function warn(string $message, ?int $lineNumber, ?int $blockIndex, array $block, ParseWarningAction $action): void
    {
        $this->warnings[] = new ParseWarning($message, $lineNumber, $blockIndex, $block, $action);
    }


    /**
     * Throws in strict mode and warns in lenient mode for text that XmlLoader::skipLeadingText() removed.
     */
    protected function skipTextBeforeXml(string $content, string $skipped): void
    {
        $lineNumber = 1 + substr_count($content, "\n", 0, (int) strpos($content, $skipped));
        $lines      = $this->lines($skipped);
        $message    = "The file has text before the XML: \"$lines[0]\".";
        if (!$this->options->lenient) {
            throw new ParsingException($message, $lineNumber);
        }

        $this->warn("$message The parser skipped it.", $lineNumber, null, $lines, ParseWarningAction::Repaired);
    }


    /**
     * Returns $seconds, or throws when the time $text reaches MAX_HOURS.
     */
    protected static function boundedTime(float $seconds, string $text, ?int $lineNumber): float
    {
        if ($seconds >= self::MAX_HOURS * 3600) {
            throw new ParsingException("The time \"$text\" is not below " . self::MAX_HOURS . " hours.", $lineNumber);
        }

        return $seconds;
    }


    /**
     * Returns $seconds, or throws when the time in the JSON field $path reaches MAX_HOURS.
     */
    protected static function boundedField(float $seconds, string $path): float
    {
        if ($seconds >= self::MAX_HOURS * 3600) {
            throw new ParsingException("The field $path is not below " . self::MAX_HOURS . " hours.");
        }

        return $seconds;
    }


    /**
     * Throws when a core word timestamp in $lines reaches MAX_HOURS.
     *
     * @param list<string> $lines
     */
    protected static function checkWordTimestamps(array $lines, ?int $lineNumber): void
    {
        foreach ($lines as $line) {
            preg_match_all(Markup::WORD_TIMESTAMP_REGEX, $line, $timestamps);
            foreach ($timestamps[1] as $timestamp) {
                self::boundedTime(Markup::wordTimestampSeconds($timestamp), substr($timestamp, 1, -1), $lineNumber);
            }
        }
    }


    /**
     * Decodes $content as a JSON object. An invalid UTF-8 byte becomes U+FFFD, so one broken byte does not fail the file.
     *
     * @return array<string, mixed>
     */
    protected function decodeJsonObject(string $content): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $exception) {
            throw new ParsingException("The content is not valid JSON: {$exception->getMessage()}.");
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ParsingException("The JSON root must be an object.");
        }

        return $data;
    }


    /**
     * Returns true for an int or float that is finite and 0 or more. A time in a JSON format must pass it.
     */
    protected static function isTime(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && OptionChecks::isNonNegativeFinite($value);
    }


    /**
     * Returns the end of the cue at $index: the first later start in $starts.
     * Without one, the end is the start plus ReadOptions::$lastCueDuration.
     *
     * @param list<float> $starts
     */
    protected function endAtNextStart(array $starts, int $index): float
    {
        for ($next = $index + 1; $next < count($starts); $next++) {
            if ($starts[$next] > $starts[$index]) {
                return $starts[$next];
            }
        }

        return Timecode::roundToMilliseconds($starts[$index] + $this->options->lastCueDuration);
    }


    /**
     * Sorts the chapters by start and sets their ends.
     * A chapter ends at its value in $ends, else at the start of the next chapter.
     * The last chapter ends at ChapterReadOptions::$mediaDuration, but not before it starts.
     *
     * @param list<SubtitleCue> $chapters
     * @param array<int, float|null> $ends the end that the file gives, keyed like $chapters
     *
     * @return list<SubtitleCue>
     */
    protected function endChapters(array $chapters, array $ends = []): array
    {
        uasort($chapters, fn (SubtitleCue $a, SubtitleCue $b): int => $a->getStart() <=> $b->getStart());
        $keys     = array_keys($chapters);
        $chapters = array_values($chapters);
        foreach ($chapters as $index => $cue) {
            $next = $chapters[$index + 1] ?? null;
            $cue->setEnd($ends[$keys[$index]] ?? $next?->getStart() ?? max($cue->getStart(), $this->formatOptions()->mediaDuration ?? 0));
        }

        return $chapters;
    }


    /**
     * Returns the lines of $content without the line endings. A line ends at LF, CR LF or CR.
     *
     * @return list<string>
     */
    protected function lines(string $content): array
    {
        return explode(LineEnding::Lf->value, StringHelpers::normalizeEOLs($content));
    }


    /**
     * Splits $text at each "|", the line break of MPL2 and TMPlayer. Returns the trimmed lines that are not empty.
     *
     * @return list<string>
     */
    protected function pipeLines(string $text): array
    {
        return array_values(array_filter(array_map("trim", explode("|", $text)), fn (string $line): bool => $line !== ""));
    }


    /**
     * Yields the trimmed lines of each block between empty lines, keyed by the 1-based number of its first line.
     * The keys of $lines are the 0-based line numbers. An input without text yields no block.
     *
     * @return Generator<int, list<string>>
     */
    protected function splitAtEmptyLines(iterable $lines): Generator
    {
        $block = [];
        $start = 1;
        foreach ($lines as $index => $line) {
            $line = trim(StringHelpers::normalizeSpaces($line));
            if ($line !== "") {
                if ($block === []) {
                    $start = $index + 1;
                }
                $block[] = $line;
                continue;
            }
            if ($block !== []) {
                yield $start => $block;
                $block = [];
            }
        }

        if ($block !== []) {
            yield $start => $block;
        }
    }


    /**
     * Yields the blocks of splitAtEmptyLines() and joins a block of cue text to the block before it, when that block has a timing line.
     * So an empty line inside the cue text, or between the timing line and the text, does not end the cue.
     * A block of cue text has no timing line, and no cue number or time on its first line. In lenient mode, it warns for each join.
     * $isOtherBlock returns true for a block that is no cue text, such as a WebVTT NOTE block.
     *
     * @param iterable<int, list<string>> $blocks
     * @param (callable(list<string>): bool)|null $isOtherBlock
     *
     * @return Generator<int, list<string>>
     */
    protected function joinCueTextBlocks(iterable $blocks, callable $isTimingLine, bool $withCueNumbers, ?callable $isOtherBlock = null): Generator
    {
        $pending     = null;
        $pendingLine = 0;
        $blockIndex  = 0;
        foreach ($blocks as $lineNumber => $block) {
            if ($pending !== null && $this->isCueTextBlock($block, $isTimingLine, $withCueNumbers)
                && ($isOtherBlock === null || !$isOtherBlock($block))
                && array_filter($pending, $isTimingLine) !== []) {
                if ($this->options->lenient) {
                    $this->warn(
                        "Block #$blockIndex has an empty line before line $lineNumber inside the cue. The parser kept the text after it in the cue.",
                        $lineNumber,
                        $blockIndex,
                        $block,
                        ParseWarningAction::Repaired
                    );
                }
                $pending = array_merge($pending, $block);
                continue;
            }
            if ($pending !== null) {
                yield $pendingLine => $pending;
                $blockIndex++;
            }
            $pending     = $block;
            $pendingLine = $lineNumber;
        }

        if ($pending !== null) {
            yield $pendingLine => $pending;
        }
    }


    /**
     * A first line that looks like a broken time keeps the block apart, so the parser still skips it with a warning.
     *
     * @param list<string> $block
     */
    private function isCueTextBlock(array $block, callable $isTimingLine, bool $withCueNumbers): bool
    {
        return array_filter($block, $isTimingLine) === []
            && !($withCueNumbers && is_numeric($block[0]))
            && preg_match('/^\d+\s*[:：]\d|-+>/u', $block[0]) !== 1;
    }


    /**
     * Returns the parts of a block that splitAtTimingLines() returns. In lenient mode, it warns for each split.
     *
     * @param list<string> $block
     *
     * @return array<int, list<string>>
     */
    protected function repairMissingEmptyLines(array $block, int $lineNumber, int $blockIndex, callable $isTimingLine, bool $withCueNumbers): array
    {
        $parts = $this->splitAtTimingLines($block, $isTimingLine, $withCueNumbers);
        foreach (array_keys($parts) as $offset) {
            if ($offset > 0 && $this->options->lenient) {
                $this->warn(
                    "Block #$blockIndex has no empty line before line " . ($lineNumber + $offset) . ". The parser split the block there.",
                    $lineNumber + $offset,
                    $blockIndex,
                    $block,
                    ParseWarningAction::Repaired
                );
            }
        }

        return $parts;
    }


    /**
     * Returns the cues of the parts that repairMissingEmptyLines() returns.
     * $parsePart gets a part and the number of its first line, and returns its cue.
     * In lenient mode, a part for which $parsePart throws is skipped with a warning.
     *
     * @param list<string> $block
     * @param callable(list<string>, int): SubtitleCue $parsePart
     *
     * @return list<SubtitleCue>
     */
    protected function parseRepairedBlock(array $block, int $lineNumber, int $blockIndex, callable $isTimingLine, bool $withCueNumbers, callable $parsePart): array
    {
        $cues = [];
        foreach ($this->repairMissingEmptyLines($block, $lineNumber, $blockIndex, $isTimingLine, $withCueNumbers) as $offset => $part) {
            try {
                $cues[] = $parsePart($part, $lineNumber + $offset);
            } catch (ParsingException $exception) {
                $this->fail($exception, $lineNumber + $offset, $blockIndex, $part);
            }
        }

        return $cues;
    }


    /**
     * Splits a block before each line that $isTimingLine accepts, and before the cue number line in front of it.
     * Each part is keyed by its 0-based offset in the block.
     * A part that starts at a timing line has no cue number.
     *
     * @param list<string> $block
     *
     * @return array<int, list<string>>
     */
    protected function splitAtTimingLines(array $block, callable $isTimingLine, bool $withCueNumbers): array
    {
        $starts     = [0];
        $lastTiming = null;
        foreach ($block as $offset => $line) {
            if (!$isTimingLine($line)) {
                continue;
            }

            $start = $offset;
            if ($withCueNumbers && $offset > 0 && is_numeric($block[$offset - 1])
                && ($lastTiming === null || $offset - 1 > $lastTiming)) {
                $start = $offset - 1;
            }
            if ($start > 0) {
                $starts[] = $start;
            }
            $lastTiming = $offset;
        }

        $parts = [];
        foreach ($starts as $i => $start) {
            $end = $starts[$i + 1] ?? count($block);
            if ($end > $start) {
                $parts[$start] = array_slice($block, $start, $end - $start);
            }
        }

        return $parts;
    }


    private function shortName(string $class): string
    {
        return substr(strrchr("\\" . $class, "\\"), 1);
    }
}
