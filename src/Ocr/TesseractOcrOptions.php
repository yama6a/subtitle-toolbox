<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * The settings of TesseractOcrEngine.
 */
final readonly class TesseractOcrOptions
{
    /**
     * @param string     $language             a Tesseract code such as "deu" or "deu+eng" for the cues where
     *                                         recognizeText() passes no language
     * @param int        $pageSegmentationMode the --psm value of tesseract, from 0 to 13. 6 reads the image as one
     *                                         block of text
     * @param string     $program              the path or name of the tesseract program
     * @param float|null $scale                the factor from 1 to 8 that scales the image up before OCR, or null for
     *                                         2 on screens below 720 lines and 1 on larger screens
     * @param bool       $invert               draws light text dark on white, as Tesseract expects
     * @param int|null   $threshold            makes grey levels below this value from 1 to 255 black and the others
     *                                         white, or null to keep the grey levels
     */
    public function __construct(
        public string $language = "eng",
        public int $pageSegmentationMode = 6,
        public string $program = "tesseract",
        public ?float $scale = null,
        public bool $invert = true,
        public ?int $threshold = null,
    ) {
        if ($pageSegmentationMode < 0 || $pageSegmentationMode > 13) {
            throw new InvalidArgumentException("Cannot create TesseractOcrOptions with page segmentation mode " .
                                               "$pageSegmentationMode - the mode must be from 0 to 13!");
        }
        if ($scale !== null && ($scale < 1 || $scale > 8)) {
            throw new InvalidArgumentException("Cannot create TesseractOcrOptions with scale $scale - the scale " .
                                               "must be from 1 to 8!");
        }
        if ($threshold !== null && ($threshold < 1 || $threshold > 255)) {
            throw new InvalidArgumentException("Cannot create TesseractOcrOptions with threshold $threshold - the " .
                                               "threshold must be from 1 to 255!");
        }
    }
}
