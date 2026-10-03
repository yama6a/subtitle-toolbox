<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

/**
 * Writes PGS segments for test fixtures. Pixels are strings with one palette entry byte per pixel, row by row.
 */
final class PgsFixtureWriter
{
    public const STATE_NORMAL            = 0x00;
    public const STATE_ACQUISITION_POINT = 0x40;
    public const STATE_EPOCH_START       = 0x80;

    private string $bytes = "";


    public function bytes(): string
    {
        return $this->bytes;
    }


    public function segment(int $pts, int $type, string $data): self
    {
        $this->bytes .= "PG" . pack("NNCn", $pts, 0, $type, strlen($data)) . $data;

        return $this;
    }


    /**
     * @param list<array{id: int, window: int, x: int, y: int, forced?: bool, crop?: array{int, int, int, int}}> $objects
     */
    public function presentation(int $pts, int $width, int $height, int $number, int $state, int $paletteId,
                                 array $objects, bool $paletteUpdate = false): self
    {
        $data = pack("nnCnCCCC", $width, $height, 0x10, $number, $state, $paletteUpdate ? 0x80 : 0, $paletteId, count($objects));
        foreach ($objects as $object) {
            $flags = (isset($object["crop"]) ? 0x80 : 0) | (($object["forced"] ?? false) ? 0x40 : 0);
            $data .= pack("nCCnn", $object["id"], $object["window"], $flags, $object["x"], $object["y"]);
            if (isset($object["crop"])) {
                $data .= pack("n4", ...$object["crop"]);
            }
        }

        return $this->segment($pts, 0x16, $data);
    }


    /**
     * @param array<int, array{int, int, int, int}> $windows window id => [x, y, width, height]
     */
    public function windows(int $pts, array $windows): self
    {
        $data = chr(count($windows));
        foreach ($windows as $id => [$x, $y, $width, $height]) {
            $data .= pack("Cnnnn", $id, $x, $y, $width, $height);
        }

        return $this->segment($pts, 0x17, $data);
    }


    /**
     * @param array<int, array{int, int, int, int}> $entries entry id => [Y, Cr, Cb, alpha]
     */
    public function palette(int $pts, int $id, int $version, array $entries): self
    {
        $data = pack("CC", $id, $version);
        foreach ($entries as $entryId => $entry) {
            $data .= pack("C5", $entryId, ...$entry);
        }

        return $this->segment($pts, 0x14, $data);
    }


    /**
     * Writes one object, split into several segments when its run-length data is longer than $fragmentSize bytes.
     */
    public function object(int $pts, int $id, int $version, int $width, int $height, string $pixels, int $fragmentSize = 65000): self
    {
        $rle       = self::encodeRle($pixels, $width);
        $fragments = str_split($rle, $fragmentSize);
        foreach ($fragments as $index => $fragment) {
            $sequence = ($index === 0 ? 0x80 : 0) | ($index === count($fragments) - 1 ? 0x40 : 0);
            $data     = pack("nCC", $id, $version, $sequence);
            if ($index === 0) {
                $length = strlen($rle) + 4;
                $data  .= pack("Cn", $length >> 16, $length & 0xFFFF) . pack("nn", $width, $height);
            }
            $this->segment($pts, 0x15, $data . $fragment);
        }

        return $this;
    }


    public function end(int $pts): self
    {
        return $this->segment($pts, 0x80, "");
    }


    /**
     * Encodes rows of palette entry bytes with every code of the PGS run-length table.
     */
    public static function encodeRle(string $pixels, int $width): string
    {
        $rle = "";
        foreach (str_split($pixels, $width) as $row) {
            $position = 0;
            while ($position < $width) {
                $color = ord($row[$position]);
                $run   = min(strspn($row, $row[$position], $position), 0x3FFF);
                $rle  .= match (true) {
                    $color !== 0 && $run <= 2 => str_repeat(chr($color), $run),
                    $color === 0 && $run < 64 => "\0" . chr($run),
                    $color === 0              => "\0" . chr(0x40 | $run >> 8) . chr($run & 0xFF),
                    $run < 64                 => "\0" . chr(0x80 | $run) . chr($color),
                    default                   => "\0" . chr(0xC0 | $run >> 8) . chr($run & 0xFF) . chr($color),
                };
                $position += $run;
            }
            $rle .= "\0\0";
        }

        return $rle;
    }


    public static function canvas(int $width, int $height, int $color = 0): string
    {
        return str_repeat(chr($color), $width * $height);
    }


    public static function rectangle(string $pixels, int $width, int $x, int $y, int $rectangleWidth, int $rectangleHeight, int $color): string
    {
        for ($row = $y; $row < $y + $rectangleHeight; $row++) {
            $pixels = substr_replace($pixels, str_repeat(chr($color), $rectangleWidth), $row * $width + $x, $rectangleWidth);
        }

        return $pixels;
    }


    /**
     * Draws a filled box with an outline of $border pixels, the shape of an outlined subtitle line.
     */
    public static function outlinedBox(string $pixels, int $width, int $x, int $y, int $boxWidth, int $boxHeight,
                                       int $border, int $outlineColor, int $fillColor): string
    {
        $pixels = self::rectangle($pixels, $width, $x, $y, $boxWidth, $boxHeight, $outlineColor);

        return self::rectangle($pixels, $width, $x + $border, $y + $border, $boxWidth - 2 * $border, $boxHeight - 2 * $border, $fillColor);
    }


    /**
     * Draws a row of alternating colours, so the encoder writes single pixel codes.
     */
    public static function dottedLine(string $pixels, int $width, int $x, int $y, int $length, int $colorA, int $colorB): string
    {
        $line = "";
        for ($index = 0; $index < $length; $index++) {
            $line .= chr($index % 2 === 0 ? $colorA : $colorB);
        }

        return substr_replace($pixels, $line, $y * $width + $x, $length);
    }
}
