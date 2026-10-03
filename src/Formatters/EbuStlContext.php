<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use SubtitleToolbox\FrameRate;

/**
 * @internal The values that EbuStlFormatter reads during one format() call.
 */
final readonly class EbuStlContext
{
    public function __construct(
        public FrameRate $frameRate,
        public float $offset,
        public string $characterCodeTable,
        public int $maxRow,
        public bool $teletext,
        public bool $stripAll,
    ) {
    }
}
