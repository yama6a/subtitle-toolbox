<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use SubtitleToolbox\Image\CueImage;

interface OcrEngine
{
    /**
     * Returns the text lines in $image, in plain text or core markup, for a language code that the engine knows.
     */
    public function recognize(CueImage $image, ?string $language): OcrResult;
}
