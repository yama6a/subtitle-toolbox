<?php

namespace SubtitleToolbox\Container\Matroska;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../../files/mkv/generator/MkvFixtureWriter.php";

class EbmlReaderTest extends TestCase
{
    public static function vints(): array
    {
        return [
            "1-byte ID with marker"    => ["\xA3", true, [0xA3, 1]],
            "4-byte ID with marker"    => ["\x1A\x45\xDF\xA3", true, [0x1A45DFA3, 4]],
            "1-byte size"              => ["\x85", false, [5, 1]],
            "2-byte size"              => ["\x40\x7F", false, [127, 2]],
            "8-byte size"              => ["\x01\x00\x00\x00\x00\x01\x00\x00", false, [65536, 8]],
            "first byte 0"             => ["\x00\x81", false, null],
            "too few bytes"            => ["\x40", false, null],
        ];
    }


    #[DataProvider("vints")]
    public function testReadsVariableLengthIntegers(string $bytes, bool $keepMarker, ?array $expected): void
    {
        $this->assertSame($expected, EbmlReader::readVint($bytes, 0, $keepMarker));
    }


    public function testReadsElementHeadersAndValues(): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, MkvFixtureWriter::uint(0x2AD7B1, 100000) . MkvFixtureWriter::element(0x4282, "webm\0\0") .
                        MkvFixtureWriter::element(0x4489, pack("E", 7200000.5)) . MkvFixtureWriter::unknownSizeElement(0x1F43B675, ""));
        rewind($stream);
        $reader = new EbmlReader($stream);

        $this->assertSame(["id" => 0x2AD7B1, "size" => 3, "offset" => 4], $reader->readElementHeader());
        $this->assertSame(100000, $reader->readUnsigned(3));
        $this->assertSame(0x4282, $reader->readElementHeader()["id"]);
        $this->assertSame("webm", $reader->readString(6));
        $this->assertSame(8, $reader->readElementHeader()["size"]);
        $this->assertSame(7200000.5, $reader->readFloat(8));
        $this->assertSame(EbmlReader::UNKNOWN_SIZE, $reader->readElementHeader()["size"]);
        $this->assertNull($reader->readElementHeader());
    }
}
