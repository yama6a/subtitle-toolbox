<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use DOMDocument;
use DOMElement;

/**
 * The values that TtmlFormatter reads and changes during one format() call.
 *
 * @internal
 */
final class TtmlContext
{
    /** @var array<string, string> agent name => xml:id */
    public array $agentIds = [];

    /** @var array<int, string> alignment => region xml:id */
    public array $regionIds = [];

    /** @var array<string, true> */
    public array $usedIds = [];

    /** @var array<string, bool> region xml:id => itts:forcedDisplay of the region */
    public array $forcedRegions = [];


    /**
     * @param array<string, string> $namespaces prefix => namespace URI, "" for the default namespace
     */
    public function __construct(
        public readonly string $namespace,
        public array $namespaces,
        public readonly string $tts,
        public readonly string $ttm,
        public readonly DOMDocument $headDocument,
        public readonly DOMElement $head,
    ) {
    }
}
