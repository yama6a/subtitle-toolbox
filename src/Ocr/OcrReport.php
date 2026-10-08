<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

final class OcrReport
{
    /**
     * @internal
     *
     * @param array<int, RecognizedText> $texts the text that the engine read, by cue index
     */
    public function __construct(
        public readonly array $texts,
    ) {
    }
}
