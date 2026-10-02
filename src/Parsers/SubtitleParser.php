<?php

namespace SubtitleToolbox\Parsers;

use Generator;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;

abstract class SubtitleParser
{
    protected bool $lenient = false;

    /** @var list<ParseWarning> */
    protected array $warnings = [];


    abstract public function parse(string $rawSubtitle): Subtitle;


    /**
     * Makes the parser skip or repair a broken block and record a ParseWarning instead of throwing. The SCC, PGS and VobSub parsers ignore it.
     */
    public function setLenient(bool $lenient = true): static
    {
        $this->lenient = $lenient;

        return $this;
    }


    public function isLenient(): bool
    {
        return $this->lenient;
    }


    /**
     * Returns the warnings of the last parse() call in lenient mode.
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
