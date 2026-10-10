<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

final class WebVttWriteReport
{
    /**
     * @internal
     *
     * @param string                   $content       the WebVTT output, as WebVttFormatter::format() returns it
     * @param list<WebVttDroppedColor> $droppedColors each text color that the output leaves out, in the order of the cues
     */
    public function __construct(
        public readonly string $content,
        public readonly array $droppedColors,
    ) {
    }
}
