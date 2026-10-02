<?php

namespace SubtitleToolbox\Image;

use InvalidArgumentException;

final class PngEncoder
{
    private const SIGNATURE        = "\x89PNG\r\n\x1a\n";
    private const STORED_BLOCK_MAX = 65535;


    /**
     * Encodes $pixels, a row-major list of 0xRRGGBBAA integers, as an 8-bit RGBA PNG string.
     *
     * @param list<int> $pixels
     * @param bool $compress false writes uncompressed deflate blocks, which is also the fallback without ext-zlib
     */
    public static function encode(int $width, int $height, array $pixels, bool $compress = true): string
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("Cannot encode a PNG of {$width}x{$height} pixels - " .
                                               "the width and the height must be at least 1!");
        }
        if (count($pixels) !== $width * $height) {
            throw new InvalidArgumentException("Cannot encode a PNG of {$width}x{$height} pixels from " .
                                               count($pixels) . " pixels - the counts must match!");
        }

        $pixels    = array_values($pixels);
        $scanlines = "";
        for ($row = 0; $row < $height; $row++) {
            $scanlines .= "\0" . pack("N*", ...array_slice($pixels, $row * $width, $width));
        }

        $imageData = $compress && function_exists("gzcompress")
            ? gzcompress($scanlines, 9)
            : self::storeUncompressed($scanlines);

        return self::SIGNATURE
            . self::chunk("IHDR", pack("NNCCCCC", $width, $height, 8, 6, 0, 0, 0))
            . self::chunk("IDAT", $imageData)
            . self::chunk("IEND", "");
    }


    private static function chunk(string $type, string $data): string
    {
        return pack("N", strlen($data)) . $type . $data . pack("N", crc32($type . $data));
    }


    private static function storeUncompressed(string $data): string
    {
        $blocks = str_split($data, self::STORED_BLOCK_MAX);
        $stream = "\x78\x01";
        foreach ($blocks as $index => $block) {
            $length  = strlen($block);
            $stream .= chr($index === count($blocks) - 1 ? 1 : 0) . pack("vv", $length, ~$length & 0xFFFF) . $block;
        }

        return $stream . hash("adler32", $data, true);
    }
}
