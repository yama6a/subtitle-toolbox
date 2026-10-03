<?php

namespace SubtitleToolbox\Parsers;

use Generator;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

abstract class SubtitleParser
{
    protected ReadOptions $options;

    protected bool $lenient = false;

    /** @var list<ParseWarning> */
    protected array $warnings = [];


    /**
     * Reads $content, which must be UTF-8 for a text format. In lenient mode, Subtitle::getParseWarnings() returns
     * what the parser skipped or repaired.
     */
    final public function parse(string $content, ReadOptions $options): Subtitle
    {
        $this->useOptions($options);

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
    protected function fail(ParsingException $exception, int $lineNumber, int $blockIndex, array $block): void
    {
        if (!$this->lenient) {
            throw $exception;
        }

        $this->warnings[] = ParseWarning::skipped($exception, $lineNumber, $blockIndex, $block);
    }


    /**
     * @param list<string> $block
     */
    protected function warn(string $message, int $lineNumber, int $blockIndex, array $block, string $action): void
    {
        $this->warnings[] = new ParseWarning($message, $lineNumber, $blockIndex, $block, $action);
    }


    /**
     * Yields the trimmed lines of each block between empty lines, keyed by the 1-based number of its first line.
     * The keys of $lines are the 0-based line numbers. An input without text yields one block with an empty line.
     *
     * @return Generator<int, list<string>>
     */
    protected function splitAtEmptyLines(iterable $lines): Generator
    {
        $block   = [];
        $start   = 1;
        $yielded = false;
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
                $yielded = true;
                $block   = [];
            }
        }

        if ($block !== []) {
            yield $start => $block;
        } elseif (!$yielded) {
            yield 1 => [""];
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
                    ParseWarning::REPAIRED
                );
            }
        }

        return $parts;
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
