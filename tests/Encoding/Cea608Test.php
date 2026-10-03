<?php

declare(strict_types=1);

namespace SubtitleToolbox\Encoding;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\InvalidArgumentException;

class Cea608Test extends TestCase
{
    public function testParityTableOfMcPoodle(): void
    {
        // The first and last rows of the 7-bit to odd parity table in SCC_FORMAT.HTML.
        $this->assertSame([0x80, 0x01, 0x02, 0x83, 0x04, 0x85, 0x86, 0x07], array_map(Cea608::withParity(...), range(0x00, 0x07)));
        $this->assertSame([0x70, 0xF1, 0xF2, 0x73, 0xF4, 0x75, 0x76, 0xF7], array_map(Cea608::withParity(...), range(0x70, 0x77)));
        $this->assertTrue(Cea608::hasOddParity(0x94));
        $this->assertFalse(Cea608::hasOddParity(0x14));
    }


    public function testEveryPacRoundTrips(): void
    {
        for ($row = 1; $row <= Cea608::ROWS; $row++) {
            for ($column = 0; $column <= 28; $column += 4) {
                foreach ([false, true] as $underline) {
                    $pac = Cea608::decodePac(...Cea608::encodePac($row, $column, Cea608::WHITE, false, $underline));
                    $this->assertSame(["row" => $row, "column" => $column, "color" => Cea608::WHITE, "italic" => false, "underline" => $underline], $pac);
                }
            }
            foreach (array_keys(Cea608::COLORS) as $color) {
                $this->assertSame($color, Cea608::decodePac(...Cea608::encodePac($row, 0, $color))["color"]);
            }
            $this->assertTrue(Cea608::decodePac(...Cea608::encodePac($row, 0, Cea608::WHITE, true))["italic"]);
        }
    }


    public function testPacTableOfMcPoodle(): void
    {
        // Row 11 white is 10 d0, row 15 column 28 underline is 94 7f, row 3 magenta underline is 92 cd.
        $this->assertSame([0x10, 0xD0], array_map(Cea608::withParity(...), Cea608::encodePac(11, 0)));
        $this->assertSame([0x94, 0x7F], array_map(Cea608::withParity(...), Cea608::encodePac(15, 28, Cea608::WHITE, false, true)));
        $this->assertSame([0x92, 0xCD], array_map(Cea608::withParity(...), Cea608::encodePac(3, 0, 6, false, true)));
    }


    public function testRowOutsideTheScreenThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cea608::encodePac(16, 0);
    }


    public function testEveryCharacterRoundTrips(): void
    {
        for ($code = 0x20; $code <= 0x7E; $code++) {
            $this->assertSame(["byte" => $code], Cea608::encodeCharacter(Cea608::standardCharacter($code)));
        }
        for ($second = 0x30; $second <= 0x3F; $second++) {
            if ($second !== 0x39) {
                $this->assertSame([0x11, $second], Cea608::encodeCharacter(Cea608::specialCharacter($second))["pair"]);
            }
        }
        foreach ([0x12, 0x13] as $first) {
            for ($second = 0x20; $second <= 0x3F; $second++) {
                $code = Cea608::encodeCharacter(Cea608::extendedCharacter($first, $second));
                $this->assertSame([$first, $second], $code["pair"]);
                $this->assertNotNull(Cea608::standardCharacter($code["byte"]));
            }
        }
        $this->assertNull(Cea608::encodeCharacter("\u{20AC}"));
    }


    public function testMidRowCodes(): void
    {
        $this->assertSame(["color" => 4, "italic" => false, "underline" => true], Cea608::decodeMidRow(0x29));
        $this->assertSame(["color" => null, "italic" => true, "underline" => false], Cea608::decodeMidRow(0x2E));
        $this->assertSame(0x2F, Cea608::encodeMidRow(null, true));
        $this->assertSame(0x20, Cea608::encodeMidRow(Cea608::WHITE, false));
    }
}
