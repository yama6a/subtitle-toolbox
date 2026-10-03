<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use GlyphOcr\Exceptions\GlyphOcrException;
use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\RecognitionResult;
use GlyphOcr\RecognizedChar;
use GlyphOcr\Recognizer;
use ReflectionMethod;
use ReflectionParameter;
use SubtitleToolbox\Exceptions\InvalidArgumentException;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Markup;
use WeakReference;

final class GlyphOcrEngine implements OcrEngine
{
    // The subtitle fonts database takes about 76 MB, so engines that are alive at the same time share one copy.
    private static ?WeakReference $subtitleFonts = null;

    private readonly Recognizer $recognizer;


    /**
     * Reads image cues with the pure PHP OCR of the package yama6a/php-glyph-ocr, with its subtitle fonts database by default.
     *
     * @param array<string, mixed> $options named arguments of the GlyphOcr\Recognizer constructor, for example
     *                                      ["italicSlant" => 0.2]
     */
    public function __construct(?GlyphDatabase $database = null, array $options = [])
    {
        self::requireClass(Recognizer::class);

        $names = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(),
                           (new ReflectionMethod(Recognizer::class, "__construct"))->getParameters());
        foreach (array_keys($options) as $name) {
            if ($name === "database" || !in_array($name, $names, true)) {
                throw new InvalidArgumentException("Cannot create a GlyphOcrEngine with the option \"$name\" - " .
                                                   "the recognizer options are: " .
                                                   implode(", ", array_diff($names, ["database"])) . "!");
            }
        }

        try {
            $this->recognizer = new Recognizer($database ?? self::subtitleFontsDatabase(), ...$options);
        } catch (GlyphOcrException $exception) {
            throw new InvalidArgumentException("Cannot create a GlyphOcrEngine - the recognizer says: " .
                                               $exception->getMessage(), $exception);
        }
    }


    /**
     * Reads the image with one recognizer for all cues, so it keeps the glyph heights it learned. It ignores $language.
     */
    public function recognize(CueImage $image, ?string $language): OcrResult
    {
        try {
            $result = $this->recognizer->recognize(Image::fromPng($image->png));
        } catch (GlyphOcrException $exception) {
            throw new InvalidArgumentException("Cannot read the cue image at {$image->x}, {$image->y} - the " .
                                               "recognizer says: " . $exception->getMessage(), $exception);
        }

        return self::toOcrResult($result);
    }


    /**
     * Maps each recognized line to one line of text. A word becomes italic when most of its characters are.
     */
    public static function toOcrResult(RecognitionResult $result): OcrResult
    {
        $lines = [];
        foreach ($result->lines as $line) {
            $words = [[]];
            foreach ($line->chars as $char) {
                if ($char->isSpace) {
                    $words[] = [];
                } else {
                    $words[count($words) - 1][] = $char;
                }
            }

            $text       = "";
            $openItalic = false;
            foreach ($words as $index => $chars) {
                $italic = count($chars) > 0
                    && 2 * count(array_filter($chars, fn (RecognizedChar $char): bool => $char->italic)) > count($chars);
                $word   = Markup::escapeText(implode("", array_map(fn (RecognizedChar $char): string => $char->text, $chars)));
                if ($openItalic && !$italic) {
                    $text      .= "</i>";
                    $openItalic = false;
                }
                $text .= $index > 0 ? " " : "";
                if ($italic && !$openItalic) {
                    $text      .= "<i>";
                    $openItalic = true;
                }
                $text .= $word;
            }
            $lines[] = $openItalic ? "$text</i>" : $text;
        }

        return new OcrResult($lines, $result->confidence());
    }


    private static function subtitleFontsDatabase(): GlyphDatabase
    {
        $database = self::$subtitleFonts?->get();
        if ($database === null) {
            $database            = GlyphDatabase::subtitleFonts();
            self::$subtitleFonts = WeakReference::create($database);
        }

        return $database;
    }


    private static function requireClass(string $class): void
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Cannot create a GlyphOcrEngine - the package yama6a/php-glyph-ocr " .
                                               "is missing! Install it with: composer require yama6a/php-glyph-ocr");
        }
    }
}
