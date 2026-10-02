<?php

namespace SubtitleToolbox\Streaming;

use Generator;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\StringHelpers;

class SubRipStreamReader implements CueStreamReader
{
    private SubRipParser $parser;


    public function __construct()
    {
        $this->parser = new SubRipParser();
    }


    public function read($stream): Generator
    {
        $index = 0;
        $block = [];
        foreach (Streams::lines($stream) as $line) {
            $line = trim(StringHelpers::normalizeSpaces($line));
            if ($line !== "") {
                $block[] = $line;
                continue;
            }
            if ($block !== []) {
                yield $this->parser->parseCueBlock($block, $index++);
                $block = [];
            }
        }

        // SubRipParser throws on an empty file, so an empty stream throws too.
        if ($block !== [] || $index === 0) {
            yield $this->parser->parseCueBlock($block === [] ? [""] : $block, $index);
        }
    }
}
