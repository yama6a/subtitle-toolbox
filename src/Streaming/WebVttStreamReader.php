<?php

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\StringHelpers;

class WebVttStreamReader implements CueStreamReader
{
    private WebVttParser $parser;

    private array $header = [];


    public function __construct()
    {
        $this->parser = new WebVttParser();
    }


    public function read($stream): Generator
    {
        $this->header = [];
        $seenCue      = false;
        $lines        = $this->trimmedLines($stream);
        if (!str_starts_with($lines->current() ?? "", "WEBVTT")) {
            throw new ParsingException("The file doesn't start with the string WEBVTT!");
        }

        foreach ($this->parser->splitIntoBlocks($lines) as $idx => $rawLines) {
            if ($idx === 0) {
                $this->header = $this->parser->parseHeader($rawLines);
                continue;
            }

            $firstLine = trim($rawLines[0]);
            switch (true) {
                case str_contains($rawLines[0], "-->") || str_contains($rawLines[1] ?? "", "-->"):
                    $seenCue = true;
                    yield $this->parser->parseCueBlock($rawLines, $idx);
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
        $gapAfter = false;
        foreach (Streams::lines($stream) as $line) {
            if (trim($line) === "") {
                $gapAfter = $held !== null;
                continue;
            }
            if ($held === null) {
                $line = ltrim($line);
            } else {
                yield $held;
                if ($gapAfter) {
                    yield "";
                }
            }
            $held     = $line;
            $gapAfter = false;
        }

        if ($held !== null) {
            yield rtrim($held);
        }
    }
}
