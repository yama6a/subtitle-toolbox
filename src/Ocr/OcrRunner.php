<?php

namespace SubtitleToolbox\Ocr;

use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Subtitle;

final class OcrRunner
{
    public function __construct(private readonly OcrEngine $engine)
    {
    }


    /**
     * Sets the lines of every image cue without text to the text that the engine reads, and keeps the images.
     *
     * @return array<int, OcrResult> the results by cue index
     */
    public function run(Subtitle $subtitle, ?string $language = null): array
    {
        $results = [];
        foreach ($subtitle->getCues() as $cueIndex => $cue) {
            if (CueImage::isImageCue($cue) && $cue->getLines() === []) {
                $results[$cueIndex] = $this->engine->recognize(CueImage::fromCue($cue), $language);
                $cue->setLines($results[$cueIndex]->lines);
            }
        }

        return $results;
    }
}
