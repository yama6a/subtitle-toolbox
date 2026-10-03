<?php

namespace SubtitleToolbox\Image;

use SubtitleToolbox\Exceptions\InvalidArgumentException;

final class PaletteReducer
{
    public const MAX_COLORS = 256;


    /**
     * Maps 0xRRGGBBAA pixels to at most $maxColors palette entries, with median cut when the image has more colors.
     *
     * Entry 0 is a fully transparent color when the image has one. A reduced palette keeps entry 0 for all pixels
     * with alpha 0, and median cut shares the other entries.
     *
     * @param list<int> $pixels
     * @return array{palette: list<int>, indexes: string} the entry colors and one entry byte per pixel
     */
    public static function reduce(array $pixels, int $maxColors = self::MAX_COLORS): array
    {
        if ($maxColors < 2 || $maxColors > self::MAX_COLORS) {
            throw new InvalidArgumentException("Cannot reduce a palette to $maxColors colors - " .
                                               "the count must be from 2 to " . self::MAX_COLORS . "!");
        }

        $counts = array_count_values($pixels);
        if (count($counts) <= $maxColors) {
            $colors = array_keys($counts);
            usort($colors, fn (int $a, int $b): int => ($a & 0xFF) === 0 ? (($b & 0xFF) === 0 ? 0 : -1) : (($b & 0xFF) === 0 ? 1 : 0));
            $entryOf = array_flip($colors);
            $palette = $colors;
        } else {
            [$palette, $entryOf] = self::medianCut($counts, $maxColors);
        }

        $characters = array_map("chr", range(0, self::MAX_COLORS - 1));
        $indexes    = "";
        foreach ($pixels as $pixel) {
            $indexes .= $characters[$entryOf[$pixel]];
        }

        return ["palette" => $palette, "indexes" => $indexes];
    }


    /**
     * @param array<int, int> $counts color => pixel count
     * @return array{list<int>, array<int, int>} the palette and the entry of each color
     */
    private static function medianCut(array $counts, int $maxColors): array
    {
        $entryOf = [];
        $opaque  = [];
        foreach ($counts as $color => $count) {
            if (($color & 0xFF) === 0) {
                $entryOf[$color] = 0;
            } else {
                $opaque[] = [$color >> 24 & 0xFF, $color >> 16 & 0xFF, $color >> 8 & 0xFF, $color & 0xFF, $count, $color];
            }
        }

        $boxes = $opaque === [] ? [] : [self::box($opaque)];
        while ($boxes !== [] && count($boxes) < $maxColors - 1) {
            $ranges   = array_column($boxes, 2);
            $boxIndex = array_search(max($ranges), $ranges, true);
            if ($ranges[$boxIndex] === 0) {
                break;
            }

            [$colors, $channel] = $boxes[$boxIndex];
            usort($colors, fn (array $a, array $b): int => $a[$channel] <=> $b[$channel] ?: $a[5] <=> $b[5]);
            $half  = array_sum(array_column($colors, 4)) / 2;
            $split = 1;
            for ($sum = $colors[0][4]; $split < count($colors) - 1 && $sum + $colors[$split][4] <= $half; $split++) {
                $sum += $colors[$split][4];
            }
            array_splice($boxes, $boxIndex, 1, [self::box(array_slice($colors, 0, $split)), self::box(array_slice($colors, $split))]);
        }

        $palette = [0];
        foreach (array_column($boxes, 0) as $box) {
            $total = array_sum(array_column($box, 4));
            $mean  = 0;
            for ($channel = 0; $channel < 4; $channel++) {
                $sum  = array_sum(array_map(fn (array $color): int => $color[$channel] * $color[4], $box));
                $mean = $mean << 8 | (int) round($sum / $total);
            }
            foreach ($box as $color) {
                $entryOf[$color[5]] = count($palette);
            }
            $palette[] = $mean;
        }

        return [$palette, $entryOf];
    }


    /**
     * @param list<array{int, int, int, int, int, int}> $colors red, green, blue, alpha, pixel count and color
     * @return array{list<array{int, int, int, int, int, int}>, int, int} the colors, the channel with the widest range and that range
     */
    private static function box(array $colors): array
    {
        $box = [$colors, 0, 0];
        for ($channel = 0; $channel < 4; $channel++) {
            $values = array_column($colors, $channel);
            if (max($values) - min($values) > $box[2]) {
                $box = [$colors, $channel, max($values) - min($values)];
            }
        }

        return $box;
    }
}
