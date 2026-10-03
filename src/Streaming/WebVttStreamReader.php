<?php

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\StringHelpers;

class WebVttStreamReader implements CueStreamReader
{
    private WebVttParser $parser;

    private array $header = [];

    private bool $lenient = false;

    /** @var list<ParseWarning> */
    private array $warnings = [];


    public function __construct()
    {
        $this->parser = new WebVttParser();
    }


    /**
     * Makes the reader skip or repair a broken block and record a ParseWarning instead of throwing, as WebVttParser does.
     */
    public function setLenient(bool $lenient = true): static
    {
        $this->lenient = $lenient;

        return $this;
    }


    /**
     * Returns the warnings of the current or last read() so far.
     *
     * @return list<ParseWarning>
     */
    public function getWarnings(): array
    {
        $warnings = array_merge($this->parser->getWarnings(), $this->warnings);
        usort($warnings, fn (ParseWarning $a, ParseWarning $b): int => $a->lineNumber <=> $b->lineNumber);

        return $warnings;
    }


    public function read($stream): Generator
    {
        $this->parser   = (new WebVttParser())->useOptions(new ReadOptions(lenient: $this->lenient));
        $this->header   = [];
        $this->warnings = [];
        $seenCue        = false;
        $lines          = $this->trimmedLines($stream);
        if (!str_starts_with($lines->current() ?? "", "WEBVTT")) {
            throw new ParsingException("The file doesn't start with the string WEBVTT!");
        }

        $count = 0;
        foreach ($this->parser->numberedBlocks($lines) as $lineNumber => $rawLines) {
            $idx = $count++;
            if ($idx === 0) {
                $this->header = $this->parser->parseHeader($rawLines);
                continue;
            }

            $firstLine = trim($rawLines[0]);
            $cue       = null;
            try {
                switch (true) {
                    case str_contains($rawLines[0], "-->") || str_contains($rawLines[1] ?? "", "-->"):
                        $cue     = $this->parser->parseCueBlock($rawLines, $idx);
                        $seenCue = true;
                        break;
                    case !$seenCue && $firstLine === "STYLE":
                        $this->header["styles"][] = implode(StringHelpers::UNIX_LINE_ENDING, array_slice($rawLines, 1));
                        break;
                    case !$seenCue && $firstLine === "REGION":
                        $this->header["regions"][] = $this->parser->parseSettings(
                            implode(" ", array_slice($rawLines, 1)),
                            WebVttParser::REGION_SETTINGS
                        );
                        break;
                    case preg_match("/^(NOTE|STYLE|REGION)/i", $firstLine) === 1:
                        break;
                    default:
                        throw new ParsingException("Block #$idx doesn't match anything that we can parse as a WebVTT cue!");
                }
            } catch (ParsingException $exception) {
                if (!$this->lenient) {
                    throw $exception;
                }
                $this->warnings[] = ParseWarning::skipped($exception, $lineNumber, $idx, $rawLines);
            }
            if ($cue !== null) {
                yield $cue;
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
