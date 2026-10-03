<?php

declare(strict_types=1);

namespace SubtitleToolbox\Ocr;

require_once __DIR__ . "/TrueTypeFont.php";
require_once __DIR__ . "/Rasterizer.php";

/**
 * Draws centred text lines in Liberation Sans as fill and outline coverage from 0 to 1, row by row.
 * A line can hold <i> runs, which use the italic font.
 */
final class TextBitmap
{
    private const FONTS = __DIR__ . "/../fonts/";


    /**
     * @param list<float> $fill
     * @param list<float> $outline
     */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $fill,
        public readonly array $outline,
    ) {
    }


    /**
     * @param list<string> $lines
     */
    public static function render(array $lines, float $size, float $outlineWidth, int $padding): self
    {
        $regular    = self::font("LiberationSans-Regular.ttf");
        $scale      = $size / $regular->unitsPerEm;
        $lineStep   = (int)ceil(($regular->ascender - $regular->descender) * $scale * 1.05);
        $margin     = (int)ceil($outlineWidth) + 2;
        $runs       = array_map(self::runs(...), $lines);
        $widths     = array_map(fn (array $lineRuns): float => array_sum(array_map(
            fn (array $run): float => self::advance($run[0], $run[1]) * $scale, $lineRuns)), $runs);
        $width      = (int)ceil(max($widths)) + 2 * ($padding + $margin);
        $height     = count($lines) * $lineStep + 2 * ($padding + $margin);
        $rasterizer = new Rasterizer($width, $height);

        foreach ($runs as $index => $lineRuns) {
            $x        = ($width - $widths[$index]) / 2;
            $baseline = $padding + $margin + $index * $lineStep + $regular->ascender * $scale;
            foreach ($lineRuns as [$font, $text]) {
                foreach (mb_str_split($text) as $char) {
                    $glyph = $font->glyphId(mb_ord($char));
                    $rasterizer->addContours($font->contours($glyph), $scale, $x, $baseline);
                    $x += $font->advance($glyph) * $scale;
                }
            }
        }

        $fill = $rasterizer->coverage();

        return new self($width, $height, $fill, self::dilate($fill, $width, $height, $outlineWidth));
    }


    private static function font(string $file): TrueTypeFont
    {
        static $fonts = [];

        return $fonts[$file] ??= new TrueTypeFont((string)file_get_contents(self::FONTS . $file));
    }


    /**
     * @return list<array{TrueTypeFont, string}>
     */
    private static function runs(string $line): array
    {
        $runs   = [];
        $italic = false;
        foreach (preg_split("/(<\/?i>)/", $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if ($part === "<i>" || $part === "</i>") {
                $italic = $part === "<i>";
                continue;
            }
            $runs[] = [self::font($italic ? "LiberationSans-Italic.ttf" : "LiberationSans-Regular.ttf"), $part];
        }

        return $runs;
    }


    private static function advance(TrueTypeFont $font, string $text): int
    {
        $total = 0;
        foreach (mb_str_split($text) as $char) {
            $total += $font->advance($font->glyphId(mb_ord($char)));
        }

        return $total;
    }


    /**
     * Returns the outline coverage: the highest fill coverage within the outline width, with a soft edge.
     *
     * @param list<float> $coverage
     * @return list<float>
     */
    private static function dilate(array $coverage, int $width, int $height, float $radius): array
    {
        $reach   = (int)ceil($radius + 0.5);
        $offsets = [];
        for ($dy = -$reach; $dy <= $reach; $dy++) {
            for ($dx = -$reach; $dx <= $reach; $dx++) {
                $weight = min(1.0, max(0.0, $radius + 0.5 - sqrt($dx * $dx + $dy * $dy)));
                if ($weight > 0) {
                    $offsets[] = [$dx, $dy, $weight];
                }
            }
        }

        $result = array_fill(0, $width * $height, 0.0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $value = $coverage[$y * $width + $x];
                if ($value <= 0.0) {
                    continue;
                }
                foreach ($offsets as [$dx, $dy, $weight]) {
                    $tx = $x + $dx;
                    $ty = $y + $dy;
                    if ($tx >= 0 && $ty >= 0 && $tx < $width && $ty < $height) {
                        $result[$ty * $width + $tx] = max($result[$ty * $width + $tx], $value * $weight);
                    }
                }
            }
        }

        return $result;
    }
}
