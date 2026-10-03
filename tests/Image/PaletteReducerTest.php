<?php

declare(strict_types=1);

namespace SubtitleToolbox\Image;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class PaletteReducerTest extends TestCase
{
    /**
     * @param array{palette: list<int>, indexes: string} $reduced
     * @return list<int>
     */
    private function expand(array $reduced): array
    {
        return array_map(fn (int $entry): int => $reduced["palette"][$entry], array_values(unpack("C*", $reduced["indexes"])));
    }


    public function testKeepsUpTo256ColorsExactlyWithTheTransparentColorFirst(): void
    {
        $pixels = [0xFFFFFFFF, 0x000000FF, 0x00000000, 0xFFFFFFFF];
        for ($color = 0; $color < 253; $color++) {
            $pixels[] = $color << 8 | 0x80;
        }

        $reduced = PaletteReducer::reduce($pixels);

        $this->assertCount(256, $reduced["palette"]);
        $this->assertSame([0x00000000, 0xFFFFFFFF, 0x000000FF], array_slice($reduced["palette"], 0, 3));
        $this->assertSame("\1\2\0\1", substr($reduced["indexes"], 0, 4));
        $this->assertSame($pixels, $this->expand($reduced));
    }


    public function testReducesMoreColorsTo255AndOneTransparentEntry(): void
    {
        $pixels = [];
        for ($index = 0; $index < 4000; $index++) {
            $pixels[] = $index % 7 === 0 ? ($index << 8) & 0xFFFFFF00 : (($index * 2654435761) & 0xFFFFFF00) | 0xFF;
        }

        $reduced = PaletteReducer::reduce($pixels);
        $error   = 0;
        foreach ($this->expand($reduced) as $index => $pixel) {
            if ($index % 7 === 0) {
                $this->assertSame(0x00000000, $pixel);
                continue;
            }
            for ($shift = 8; $shift < 32; $shift += 8) {
                $error = max($error, abs(($pixel >> $shift & 0xFF) - ($pixels[$index] >> $shift & 0xFF)));
            }
            $this->assertSame(0xFF, $pixel & 0xFF);
        }

        $this->assertCount(256, $reduced["palette"]);
        $this->assertSame(0x00000000, $reduced["palette"][0]);
        $this->assertLessThan(64, $error);
        $this->assertSame($reduced, PaletteReducer::reduce($pixels));
    }


    public function testReducesToASmallerPalette(): void
    {
        $pixels  = [0x00000000, 0xFF0000FF, 0xFE0000FF, 0x0000FFFF, 0x0000FEFF, 0xFF0000FF, 0xFE0000FF, 0x0000FFFF, 0x0000FEFF];
        $reduced = PaletteReducer::reduce($pixels, 3);

        $this->assertSame([0x00000000, 0x0000FFFF, 0xFF0000FF], $reduced["palette"]);
        $this->assertSame("\0\2\2\1\1\2\2\1\1", $reduced["indexes"]);
    }


    public function testRejectsAPaletteSizeAbove256(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaletteReducer::reduce([0], 257);
    }
}
