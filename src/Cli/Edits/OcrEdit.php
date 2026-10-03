<?php

declare(strict_types=1);

namespace SubtitleToolbox\Cli\Edits;

use GlyphOcr\Exceptions\GlyphOcrException;
use GlyphOcr\GlyphDatabase;
use SubtitleToolbox\Cli\Arguments;
use SubtitleToolbox\Cli\Command;
use SubtitleToolbox\Cli\Console;
use SubtitleToolbox\Cli\OcrProgress;
use SubtitleToolbox\Cli\Option;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Ocr\GlyphOcrEngine;
use SubtitleToolbox\Ocr\OcrEngine;
use SubtitleToolbox\Ocr\OcrEngineChooser;
use SubtitleToolbox\Ocr\TesseractOcrEngine;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

final class OcrEdit extends Edit
{
    private bool $warnedAboutLanguage = false;


    private function __construct(
        private readonly string $engine,
        private readonly ?string $language,
        private readonly ?GlyphDatabase $database,
    ) {
    }


    public static function options(): array
    {
        return [
            Option::flag("ocr", "Read the text of image cues, for example from PGS or VobSub, with Tesseract when it is installed, else with php-glyph-ocr."),
            Option::value("ocr-engine", "ENGINE", "The OCR engine for --ocr: tesseract or glyph. Default: tesseract when it is installed."),
            Option::value("ocr-language", "CODE", "The Tesseract language for --ocr, for example deu or deu+eng. Default: eng. The glyph engine ignores it."),
            Option::value("ocr-database", "FILE", "The .nocr glyph database for --ocr. It selects the glyph engine. Default: the subtitle fonts database of php-glyph-ocr."),
        ];
    }


    public static function fromArguments(Arguments $arguments): ?static
    {
        self::needs($arguments, "ocr", ["ocr-database", "ocr-engine", "ocr-language"]);
        if (!$arguments->has("ocr")) {
            return null;
        }

        $engine = $arguments->value("ocr-engine");
        if ($arguments->has("ocr-database")) {
            if ($engine === OcrEngineChooser::ENGINE_TESSERACT) {
                Command::fail("--ocr-database works only with the glyph engine.");
            }
            $engine = OcrEngineChooser::ENGINE_GLYPH;
        }
        try {
            $engine = OcrEngineChooser::choose($engine);
        } catch (InvalidArgumentException $exception) {
            Command::fail($exception->getMessage());
        }

        return new self(
            $engine,
            $arguments->value("ocr-language"),
            $engine === OcrEngineChooser::ENGINE_GLYPH ? self::loadDatabase($arguments->value("ocr-database")) : null,
        );
    }


    public function apply(Subtitle $subtitle, Console $console, string $label): Subtitle
    {
        $total = count(array_filter($subtitle->getCues(), fn (SubtitleCue $cue): bool => CueImage::isImageCue($cue) && $cue->getLines() === []));
        if ($total > 0) {
            $subtitle->recognizeText(new OcrProgress($this->engine($console), $console, $label, $total));
        }

        return $subtitle;
    }


    private function engine(Console $console): OcrEngine
    {
        if ($this->engine === OcrEngineChooser::ENGINE_TESSERACT) {
            return new TesseractOcrEngine($this->language ?? "eng");
        }
        if ($this->language !== null && !$this->warnedAboutLanguage) {
            $console->err("Warning: the glyph engine ignores --ocr-language.\n");
            $this->warnedAboutLanguage = true;
        }

        // A new engine for each file, because the recognizer learns the glyph heights of one stream.
        return new GlyphOcrEngine($this->database);
    }


    private static function loadDatabase(?string $path): GlyphDatabase
    {
        try {
            return $path === null ? GlyphDatabase::subtitleFonts() : GlyphDatabase::fromFile($path);
        } catch (GlyphOcrException $exception) {
            return Command::fail($exception->getMessage());
        }
    }
}
