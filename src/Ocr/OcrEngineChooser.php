<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use GlyphOcr\Recognizer;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class OcrEngineChooser
{
    public const ENGINE_TESSERACT = "tesseract";
    public const ENGINE_GLYPH     = "glyph";

    public const ENGINES = [self::ENGINE_TESSERACT, self::ENGINE_GLYPH];

    private const GLYPH_INSTALL_HINT = "Install php-glyph-ocr with: composer require yama6a/php-glyph-ocr";


    /**
     * Returns $engine, or "tesseract" when Tesseract is installed, or else "glyph" when the package
     * yama6a/php-glyph-ocr is installed. Throws with install hints when the engine is missing.
     */
    public static function choose(?string $engine = null, string $tesseractProgram = "tesseract"): string
    {
        $tesseract = $engine !== self::ENGINE_GLYPH && TesseractOcrEngine::isInstalled($tesseractProgram);
        $glyph     = class_exists(Recognizer::class);
        $problem   = match (true) {
            $engine !== null && !in_array($engine, self::ENGINES, true)
                => "Cannot choose the OCR engine \"$engine\" - the engines are: " . implode(", ", self::ENGINES) . "!",
            $engine === self::ENGINE_TESSERACT && !$tesseract
                => "Cannot run OCR with Tesseract - the program \"$tesseractProgram\" is missing! " .
                   TesseractOcrEngine::INSTALL_HINT,
            $engine === self::ENGINE_GLYPH && !$glyph
                => "Cannot run OCR with php-glyph-ocr - the package yama6a/php-glyph-ocr is missing! " .
                   self::GLYPH_INSTALL_HINT,
            $engine === null && !$tesseract && !$glyph
                => "Cannot run OCR - neither Tesseract nor the package yama6a/php-glyph-ocr is installed! " .
                   TesseractOcrEngine::INSTALL_HINT . " Or " . lcfirst(self::GLYPH_INSTALL_HINT),
            default => null,
        };
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        return $engine ?? ($tesseract ? self::ENGINE_TESSERACT : self::ENGINE_GLYPH);
    }


    /**
     * Creates the engine that choose() names, with default options. Tesseract reads $tesseractLanguage.
     */
    public static function create(?string $engine = null, string $tesseractLanguage = "eng",
                                  string $tesseractProgram = "tesseract"): OcrEngine
    {
        return match (self::choose($engine, $tesseractProgram)) {
            self::ENGINE_TESSERACT => new TesseractOcrEngine($tesseractLanguage, program: $tesseractProgram),
            self::ENGINE_GLYPH     => new GlyphOcrEngine(),
        };
    }
}
