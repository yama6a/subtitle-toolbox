<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

final class WebVttDroppedColor
{
    /**
     * Records a <font color> in the cue at $cueIndex that no WebVTT color class has. $color holds the value as written.
     *
     * @internal
     */
    public function __construct(
        public readonly int $cueIndex,
        public readonly string $color,
        public readonly string $message,
    ) {
    }
}
