<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

enum OcrEngineName: string
{
    /** TesseractOcrEngine, which runs the tesseract program. */
    case Tesseract = "tesseract";

    /** GlyphOcrEngine, which needs the package yama6a/php-glyph-ocr. */
    case Glyph = "glyph";
}
