<?php

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\ReadOptions;

class SubRipStreamReader implements CueStreamReader
{
    private SubRipParser $parser;

    private bool $lenient = false;


    public function __construct()
    {
        $this->parser = new SubRipParser();
    }


    /**
     * Makes the reader skip or repair a broken block and record a ParseWarning instead of throwing, as SubRipParser does.
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
        return $this->parser->getWarnings();
    }


    public function read($stream): Generator
    {
        $this->parser = (new SubRipParser())->useOptions(new ReadOptions(lenient: $this->lenient));
        $index        = 0;
        foreach ($this->parser->splitIntoBlocks(Streams::lines($stream)) as $lineNumber => $rawLines) {
            foreach ($this->parser->parseBlock($rawLines, $index++, $lineNumber) as $cue) {
                yield $cue;
            }
        }
    }
}
