<?php

namespace SubtitleToolbox\Image;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class PngDecoderTest extends TestCase
{
    private const WIDTH  = 13;
    private const HEIGHT = 5;


    private static function chunk(string $type, string $data): string
    {
        return pack("N", strlen($data)) . $type . $data . pack("N", crc32($type . $data));
    }


    /**
     * Builds a PNG from unfiltered rows and filters row $index with filter type $index % 5, so one image uses all 5 types.
     *
     * @param list<string> $rows
     * @param array<string, string> $chunks extra chunks before IDAT
     */
    private static function png(int $width, int $color, int $depth, array $rows, array $chunks = []): string
    {
        $channels      = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color];
        $bytesPerPixel = max(1, intdiv($channels * $depth, 8));
        $scanlines     = "";
        $previous      = array_fill(0, strlen($rows[0]), 0);
        foreach ($rows as $index => $row) {
            $bytes    = array_values(unpack("C*", $row));
            $filtered = [];
            foreach ($bytes as $position => $byte) {
                $left  = $bytes[$position - $bytesPerPixel] ?? 0;
                $up    = $previous[$position];
                $upper = $previous[$position - $bytesPerPixel] ?? 0;
                $guess = $left + $up - $upper;
                $paeth = abs($guess - $left) <= abs($guess - $up) && abs($guess - $left) <= abs($guess - $upper)
                    ? $left : (abs($guess - $up) <= abs($guess - $upper) ? $up : $upper);

                $filtered[] = ($byte - [0, $left, $up, ($left + $up) >> 1, $paeth][$index % 5]) & 0xFF;
            }
            $scanlines .= chr($index % 5) . pack("C*", ...$filtered);
            $previous   = $bytes;
        }

        $png = "\x89PNG\r\n\x1a\n" . self::chunk("IHDR", pack("NNCCCCC", $width, count($rows), $depth, $color, 0, 0, 0));
        foreach ($chunks as $type => $data) {
            $png .= self::chunk($type, $data);
        }

        return $png . self::chunk("IDAT", gzcompress($scanlines)) . self::chunk("IEND", "");
    }


    /**
     * @return list<string>
     */
    private static function rows(int $rowLength): array
    {
        $rows = [];
        for ($row = 0; $row < self::HEIGHT; $row++) {
            $bytes = "";
            for ($position = 0; $position < $rowLength; $position++) {
                $bytes .= chr(($position * 37 + $row * 101 + $position * $row * 7) % 256);
            }
            $rows[] = $bytes;
        }

        return $rows;
    }


    /**
     * PNG files of every color type and bit depth, in the layout of the PNG spec.
     *
     * @return array<string, array{string}>
     */
    public static function handWrittenPngs(): array
    {
        $palette = "";
        for ($entry = 0; $entry < 256; $entry++) {
            $palette .= chr($entry) . chr(255 - $entry) . chr($entry * 3 % 256);
        }

        $pngs = [];
        foreach ([1, 2, 4, 8, 16] as $depth) {
            $pngs["gray $depth bit"] = self::png(self::WIDTH, 0, $depth, self::rows(intdiv(self::WIDTH * $depth + 7, 8)));
        }
        foreach ([1, 2, 4, 8] as $depth) {
            $pngs["palette $depth bit"] = self::png(self::WIDTH, 3, $depth, self::rows(intdiv(self::WIDTH * $depth + 7, 8)),
                                                    ["PLTE" => substr($palette, 0, 3 << $depth), "tRNS" => "\x00\x80"]);
        }
        $pngs["gray 8 bit, transparent key"] = self::png(self::WIDTH, 0, 8, self::rows(self::WIDTH), ["tRNS" => "\0\x25"]);
        $pngs["gray and alpha 8 bit"]        = self::png(self::WIDTH, 4, 8, self::rows(2 * self::WIDTH));
        $pngs["gray and alpha 16 bit"]       = self::png(self::WIDTH, 4, 16, self::rows(4 * self::WIDTH));
        $pngs["rgb 8 bit"]                   = self::png(self::WIDTH, 2, 8, self::rows(3 * self::WIDTH));
        $pngs["rgb 16 bit"]                  = self::png(self::WIDTH, 2, 16, self::rows(6 * self::WIDTH));
        $pngs["rgba 8 bit"]                  = self::png(self::WIDTH, 6, 8, self::rows(4 * self::WIDTH));
        $pngs["rgba 16 bit"]                 = self::png(self::WIDTH, 6, 16, self::rows(8 * self::WIDTH));

        return array_map(fn (string $png): array => [$png], $pngs);
    }


    /**
     * Checks the decoder against libpng, which GD uses. GD keeps 7 bits of alpha and sets transparent pixels to black.
     */
    #[DataProvider("handWrittenPngs")]
    public function testDecodesLikeLibpng(string $png): void
    {
        if (!function_exists("imagecreatefromstring")) {
            $this->markTestSkipped("ext-gd is not loaded");
        }

        $decoded = PngDecoder::decode($png);
        $image   = imagecreatefromstring($png);
        imagepalettetotruecolor($image);

        $this->assertSame([imagesx($image), imagesy($image)], [$decoded["width"], $decoded["height"]]);
        $this->assertCount($decoded["width"] * $decoded["height"], $decoded["pixels"]);
        foreach ($decoded["pixels"] as $index => $pixel) {
            $color = imagecolorat($image, $index % $decoded["width"], intdiv($index, $decoded["width"]));
            if (($pixel & 0xFF) === 0) {
                $pixel &= 0xFF;
            }

            $this->assertSame(sprintf("%06X %d", $color & 0xFFFFFF, $color >> 24 & 0x7F),
                              sprintf("%06X %d", $pixel >> 8, 127 - (($pixel & 0xFF) >> 1)), "pixel $index");
        }
    }


    public function testDecodesThePngsOfPngEncoder(): void
    {
        $pixels = [];
        for ($index = 0; $index < 40 * 7; $index++) {
            $pixels[] = ($index * 2654435761) & 0xFFFFFFFF;
        }

        foreach ([true, false] as $compress) {
            $this->assertSame(["width" => 40, "height" => 7, "pixels" => $pixels],
                              PngDecoder::decode(PngEncoder::encode(40, 7, $pixels, $compress)));
        }
    }


    public function testDecodesThePngsOfGd(): void
    {
        if (!function_exists("imagecreatefromstring")) {
            $this->markTestSkipped("ext-gd is not loaded");
        }

        $image = imagecreatetruecolor(30, 10);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 5, 2, 24, 7, imagecolorallocatealpha($image, 255, 255, 0, 0));
        imagefilledrectangle($image, 10, 4, 12, 5, imagecolorallocatealpha($image, 10, 20, 30, 64));
        ob_start();
        imagepng($image, null, 9, PNG_ALL_FILTERS);
        $decoded = PngDecoder::decode(ob_get_clean());

        $this->assertSame([30, 10], [$decoded["width"], $decoded["height"]]);
        $this->assertSame(0x00000000, $decoded["pixels"][0]);
        $this->assertSame(0xFFFF00FF, $decoded["pixels"][2 * 30 + 5]);
        $this->assertSame(0x0A141E7E, $decoded["pixels"][4 * 30 + 10]);
    }


    public function testATransparentKeyMatchesAllChannelsOfTheFullSampleDepth(): void
    {
        $rows = ["\x12\x34\x56\x78\x9A\xBC" . "\x12\x34\x56\x78\x9A\xBD"];
        $png  = self::png(2, 2, 16, $rows, ["tRNS" => "\x12\x34\x56\x78\x9A\xBC"]);

        $this->assertSame([0x12569A00, 0x12569AFF], PngDecoder::decode($png)["pixels"]);
    }


    public function testReadsImageDataOverSeveralIdatChunks(): void
    {
        $png    = PngEncoder::encode(3, 2, [1, 2, 3, 4, 5, 6]);
        $offset = 8 + 25;
        $length = unpack("N", $png, $offset)[1];
        $data   = substr($png, $offset + 8, $length);
        $split  = substr($png, 0, $offset) . self::chunk("IDAT", substr($data, 0, 5)) . self::chunk("tEXt", "a\0b")
                . self::chunk("IDAT", substr($data, 5)) . substr($png, $offset + 12 + $length);

        $this->assertSame([1, 2, 3, 4, 5, 6], PngDecoder::decode($split)["pixels"]);
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidPngs(): array
    {
        $valid = PngEncoder::encode(2, 2, [1, 2, 3, 4]);
        $ihdr  = fn (int $color, int $depth, int $interlace = 0): string => "\x89PNG\r\n\x1a\n" .
            self::chunk("IHDR", pack("NNCCCCC", 2, 2, $depth, $color, 0, 0, $interlace));

        return [
            "no signature"      => ["GIF89a", "does not start with the PNG signature"],
            "cut off chunk"     => [substr($valid, 0, 40), "the chunk at byte 33 is cut off"],
            "no IHDR"           => ["\x89PNG\r\n\x1a\n" . self::chunk("IEND", ""), "has no valid IHDR chunk"],
            "RGB 4 bit"         => [$ihdr(2, 4), "color type 2, bit depth 4"],
            "interlaced"        => [$ihdr(6, 8, 1), "interlace method 1"],
            "invalid zlib data" => [$ihdr(6, 8) . self::chunk("IDAT", "nope"), "no valid zlib data"],
            "too few rows"      => [$ihdr(6, 8) . self::chunk("IDAT", gzcompress("\0" . str_repeat("\1", 8))), "from 9 bytes"],
            "filter type 5"     => [$ihdr(6, 8) . self::chunk("IDAT", gzcompress(str_repeat("\5" . str_repeat("\1", 8), 2))),
                                    "unknown filter type 5"],
        ];
    }


    #[DataProvider("invalidPngs")]
    public function testRejectsInvalidPngs(string $png, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PngDecoder::decode($png);
    }


    public function testNamesExtZlibWhenItIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("ext-zlib");

        (new \ReflectionMethod(PngDecoder::class, "requireFunction"))->invoke(null, "gzuncompress_missing");
    }
}
