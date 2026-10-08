<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\SubtitleCue;

final class WebVttStreamReader implements CueStreamReader
{
    private readonly WebVttParser $parser;

    private readonly ReadOptions $options;

    private array $header = [];


    /**
     * The reader uses ReadOptions::$lenient and ignores $encoding and $lastCueDuration.
     * A ReadOptions::$format throws InvalidArgumentException.
     */
    public function __construct(?ReadOptions $options = null)
    {
        $options ??= new ReadOptions();
        $this->options = $options;
        $this->parser = (new WebVttParser())->useOptions($options);
    }


    public function getWarnings(): array
    {
        $warnings = $this->parser->getWarnings();
        usort($warnings, fn (ParseWarning $a, ParseWarning $b): int => $a->lineNumber <=> $b->lineNumber);

        return $warnings;
    }


    public function read($stream): Generator
    {
        $this->parser->useOptions($this->options);
        $this->header = [];
        $seenCue      = false;
        $lines        = $this->trimmedLines($stream);
        if (!str_starts_with($lines->current() ?? "", "WEBVTT")) {
            throw new ParsingException("The file does not start with WEBVTT.", ($lines->key() ?? 0) + 1);
        }

        $count = 0;
        foreach ($this->parser->numberedBlocks($lines) as $lineNumber => $rawLines) {
            $idx = $count++;
            if ($idx === 0) {
                $this->header = $this->parser->parseHeader($rawLines, $lineNumber);
                continue;
            }

            // The stream skips NOTE blocks, which WebVttParser keeps as comments.
            $block = $this->parser->parseBlock($rawLines, $idx, $lineNumber, $seenCue, $this->header);
            if ($block instanceof SubtitleCue) {
                $seenCue = true;
                yield $block;
            }
        }
    }


    /**
     * Returns the header, STYLE and REGION blocks as WebVttParser stores them, complete at the first cue.
     */
    public function getHeader(): array
    {
        return $this->header;
    }


    /**
     * Drops the whitespace around the file like WebVttParser does, and joins runs of empty lines into one.
     */
    private function trimmedLines($stream): Generator
    {
        $held     = null;
        $heldKey  = 0;
        $gapAfter = false;
        foreach (Streams::lines($stream) as $key => $line) {
            if (trim($line) === "") {
                $gapAfter = $held !== null;
                continue;
            }
            if ($held === null) {
                $line = ltrim($line);
            } else {
                yield $heldKey => $held;
                if ($gapAfter) {
                    yield $heldKey + 1 => "";
                }
            }
            $held     = $line;
            $heldKey  = $key;
            $gapAfter = false;
        }

        if ($held !== null) {
            yield $heldKey => rtrim($held);
        }
    }
}
