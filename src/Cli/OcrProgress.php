<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli;

use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\RecognizedText;

/**
 * @internal
 */
final class OcrProgress implements OcrEngine
{
    private const INTERVAL = 100;

    private int $done = 0;


    /**
     * Passes each image to $engine and prints "<label>: OCR <done>/<total>" to standard error every INTERVAL images
     * and after the last one.
     */
    public function __construct(
        private readonly OcrEngine $engine,
        private readonly Console $console,
        private readonly string $label,
        private readonly int $total,
    ) {
    }


    public function recognize(CueImage $image, ?string $language): RecognizedText
    {
        $result = $this->engine->recognize($image, $language);

        $this->done++;
        if ($this->done % self::INTERVAL === 0 || $this->done === $this->total) {
            $this->console->err("$this->label: OCR $this->done/$this->total\n");
        }

        return $result;
    }
}
