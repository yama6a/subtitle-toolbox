<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

use GlyphOcr\GlyphDatabase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

/**
 * The settings of GlyphOcrEngine. Each field except $database is the parameter of the same name of the
 * GlyphOcr\Recognizer constructor in php-glyph-ocr 0.3, with the same default and the same allowed values.
 */
final readonly class GlyphOcrOptions
{
    /**
     * @param GlyphDatabase|null $database       the glyphs to match, or null for GlyphDatabase::subtitleFonts()
     * @param int                $inkThreshold   a pixel is ink when the sum of its premultiplied red, green and blue is at
     *                                           least this, from 1 to 765
     * @param int|null           $spaceWidth     the empty columns between two glyphs that make a space, at least 1, or
     *                                           null to derive it from the glyph height of each line
     * @param int                $maxWrongPixels the pixels that a loose match may get wrong, at least 0
     * @param bool               $fixLatinCase   picks upper or lower case for letters such as o and O from their height
     * @param string             $unknownText    the text of a glyph that matches no glyph of the database
     * @param float              $italicSlant    from 0 to 1. Above 0, a glyph that matches nothing is slanted back by this
     *                                           factor and tried again. 0.2 fits most italic fonts
     * @param bool               $rightToLeft    puts the glyphs of each line in right to left order
     * @param int                $minLineHeight  the minimum line height in pixels until the engine has learned the glyph
     *                                           heights, at least 1
     * @param bool               $lineContext    compares each glyph with the other glyphs of its line, for example to pick
     *                                           capital I or lower case l. False keeps the database text, as Subtitle Edit does
     */
    public function __construct(
        public ?GlyphDatabase $database = null,
        public int $inkThreshold = 200,
        public ?int $spaceWidth = null,
        public int $maxWrongPixels = 25,
        public bool $fixLatinCase = true,
        public string $unknownText = "*",
        public float $italicSlant = 0.0,
        public bool $rightToLeft = false,
        public int $minLineHeight = 12,
        public bool $lineContext = true,
    ) {
        if ($inkThreshold < 1 || $inkThreshold > 765) {
            throw new InvalidArgumentException("Cannot create GlyphOcrOptions with ink threshold $inkThreshold - " .
                                               "it must be from 1 to 765!");
        }
        if ($spaceWidth !== null && $spaceWidth < 1) {
            throw new InvalidArgumentException("Cannot create GlyphOcrOptions with space width $spaceWidth - " .
                                               "it must be at least 1!");
        }
        if ($maxWrongPixels < 0) {
            throw new InvalidArgumentException("Cannot create GlyphOcrOptions with $maxWrongPixels wrong pixels - " .
                                               "the number must be at least 0!");
        }
        if ($italicSlant < 0 || $italicSlant > 1) {
            throw new InvalidArgumentException("Cannot create GlyphOcrOptions with italic slant $italicSlant - " .
                                               "it must be from 0 to 1!");
        }
        if ($minLineHeight < 1) {
            throw new InvalidArgumentException("Cannot create GlyphOcrOptions with minimum line height " .
                                               "$minLineHeight - it must be at least 1!");
        }
    }
}
