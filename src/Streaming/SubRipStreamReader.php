<?php

declare(strict_types=1);

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\ReadOptions;

final class SubRipStreamReader implements CueStreamReader
{
    private SubRipParser $parser;

    private readonly ReadOptions $options;


    /**
     * The reader uses ReadOptions::$lenient and ignores $encoding and $lastCueDuration.
     * A ReadOptions::$format throws InvalidArgumentException.
     */
    public function __construct(?ReadOptions $options = null)
    {
        $options ??= new ReadOptions();
        $this->options = $options;
        $this->parser = (new SubRipParser())->useOptions($options);
    }


    public function getWarnings(): array
    {
        return $this->parser->getWarnings();
    }


    public function read($stream): Generator
    {
        $this->parser = (new SubRipParser())->useOptions($this->options);
        $index        = 0;
        foreach ($this->parser->splitIntoBlocks(Streams::lines($stream)) as $lineNumber => $rawLines) {
            foreach ($this->parser->parseBlock($rawLines, $index++, $lineNumber) as $cue) {
                yield $cue;
            }
        }
    }
}
