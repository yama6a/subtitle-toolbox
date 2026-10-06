<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use Generator;
use JsonException;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\LineEnding;
use SubtitleToolbox\Parsers\Options\FormatReadOptions;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

/**
 * The base class of the parsers of this library. Only the library extends it. Its protected members are not API and
 * can change in any release.
 */
abstract class SubtitleParser
{
    // parse() strips the UTF-8 BOM of a text format only.
    protected const BINARY = false;

    protected ReadOptions $options;

    protected bool $lenient = false;

    /** @var list<ParseWarning> */
    protected array $warnings = [];


    /**
     * Reads $content, which must be UTF-8 for a text format. In lenient mode, Subtitle::getParseWarnings() returns
     * what the parser skipped or repaired.
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
     * Returns the FormatReadOptions class that this parser reads from ReadOptions::$format, or null for none.
     *
     * @return class-string<FormatReadOptions>|null
     */
    protected static function formatOptionsClass(): ?string
    {
        return null;
    }


    /**
     * Returns ReadOptions::$format, or the defaults of formatOptionsClass() when it is null.
     */
    protected function formatOptions(): FormatReadOptions
    {
        return $this->options->format ?? new (static::formatOptionsClass())();
    }


    /**
     * Sets the options for the next read and clears the warnings. The stream readers call it before they call the
     * block methods directly.
     *
     * @internal
     */
    public function useOptions(ReadOptions $options): static
    {
        $class = static::formatOptionsClass();
        if ($options->format !== null && ($class === null || !$options->format instanceof $class)) {
            throw new InvalidArgumentException(sprintf(
                "%s does not read %s.",
                substr(strrchr(static::class, "\\"), 1),
                substr(strrchr($options->format::class, "\\"), 1)
            ));
        }

        $this->options  = $options;
        $this->lenient  = $options->lenient;
        $this->warnings = [];

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
        if (!$this->lenient) {
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
        return (is_int($value) || is_float($value)) && is_finite($value) && $value >= 0;
    }


    /**
     * Sorts the chapters by start and sets their ends. A chapter ends at its value in $ends, else at the start of the
     * next chapter. The last chapter ends at ChapterReadOptions::$mediaDuration, but not before it starts.
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
     * Returns the parts of a block that splitAtTimingLines() returns in lenient mode, and warns for each split.
     *
     * @param list<string> $block
     *
     * @return array<int, list<string>>
     */
    protected function repairMissingEmptyLines(array $block, int $lineNumber, int $blockIndex, callable $isTimingLine, bool $withCueNumbers): array
    {
        if (!$this->lenient) {
            return [0 => $block];
        }

        $parts = $this->splitAtTimingLines($block, $isTimingLine, $withCueNumbers);
        foreach (array_keys($parts) as $offset) {
            if ($offset > 0) {
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
     * Returns the cues of the parts that repairMissingEmptyLines() returns. $parsePart gets a part and the number of its
     * first line, and returns its cue. In lenient mode, a part for which $parsePart throws is skipped with a warning.
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
     * Each part is keyed by its 0-based offset in the block. A part that starts at a timing line has no cue number.
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
}
