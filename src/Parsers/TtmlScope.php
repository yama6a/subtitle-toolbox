<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * The values that a TTML element inherits from its parent elements: the time interval, the region, the text
 * alignment, xml:space, itts:forcedDisplay and the style properties.
 *
 * @internal
 */
final class TtmlScope
{
    public function __construct(
        public readonly float $begin = 0.0,
        public readonly ?float $end = null,
        public readonly ?string $region = null,
        public readonly ?string $textAlign = null,
        public readonly bool $preserveSpace = false,
        public readonly ?bool $forced = null,
        /** @var list<array<string, string>> The style properties of <body> and each <div>, outermost first. */
        public readonly array $styleProperties = [],
    ) {
    }
}
