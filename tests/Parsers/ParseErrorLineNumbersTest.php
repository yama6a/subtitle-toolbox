<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;

class ParseErrorLineNumbersTest extends TestCase
{
    private const SRT_NO_NUMBER = "1\n00:00:01,000 --> 00:00:02,000\nThe ferry leaves.\n\nx\n00:00:03,000 --> 00:00:04,000\nIt is late.\n";
    private const SRT_BAD_TIME  = "1\n00:00:01,000 --> 00:00:02,000\nThe ferry leaves.\n\n2\n00:00:0x,000 --> 00:00:04,000\nIt is late.\n";
    private const VTT_NO_HEADER = "\n\nThe ferry leaves.\n";
    private const VTT_ARROW     = "WEBVTT\n00:00:01.000 --> 00:00:02.000\nThe ferry leaves.\n";
    private const VTT_BAD_BLOCK = "WEBVTT\n\nIt is late.\nVery late.\n\n00:00:01.000 --> 00:00:02.000\nThe ferry leaves.\n";
    private const VTT_BAD_TIME  = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nThe ferry leaves.\n\n00:00:0x.000 --> 00:00:04.000\nIt is late.\n";


    /**
     * @return array<string, array{Format, string, int, string}>
     */
    public static function brokenFiles(): array
    {
        return [
            "SubRip without cue number" => [Format::SubRip, self::SRT_NO_NUMBER, 5, "Block #1 has no cue number on its first line."],
            "SubRip with a bad time"    => [Format::SubRip, self::SRT_BAD_TIME, 5, "The time \"00:00:0x,000\" is not valid."],
            "SBV without timing line"   => [Format::Sbv, "0:00:01.000,0:00:02.000\nThe ferry leaves.\n\n0:00:03.000\nIt is late.\n", 4,
                                            "Block #1 has no timing line on its first line."],
            "SBV with a bad time"       => [Format::Sbv, "0:00:01.000,0:00:02.000\nThe ferry leaves.\n\n0:00:0x.000,0:00:04.000\nIt is late.\n", 4,
                                            "The time \"0:00:0x.000\" is not valid."],
            "WebVTT without WEBVTT"     => [Format::WebVtt, self::VTT_NO_HEADER, 3, "The file does not start with WEBVTT."],
            "WebVTT header with a cue"  => [Format::WebVtt, self::VTT_ARROW, 2, "The WEBVTT header has no empty line before the first cue."],
            "WebVTT unknown block"      => [Format::WebVtt, self::VTT_BAD_BLOCK, 3, "Block #1 is not a WebVTT cue, comment, style or region."],
            "WebVTT with a bad time"    => [Format::WebVtt, self::VTT_BAD_TIME, 6, "The time \"00:00:0x.000\" is not valid."],
            "SAMI without Start"        => [Format::Sami, "<SAMI>\n<BODY>\n<SYNC>The ferry leaves.\n</BODY>\n</SAMI>\n", 3,
                                            "SYNC tag 1 has no valid Start attribute."],
        ];
    }


    #[DataProvider("brokenFiles")]
    public function testStrictModeThrowsWithTheLineNumber(Format $format, string $content, int $line, string $message): void
    {
        try {
            Subtitle::fromString($content, $format, new ReadOptions());
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertSame($line, $exception->getLineNumber());
            $this->assertSame("ParsingException (Error #100): $message (line $line)", $exception->getMessage());
        }
    }


    #[DataProvider("brokenFiles")]
    public function testLenientModeWarnsWithTheSameLineAndNoSuffix(Format $format, string $content, int $line, string $message): void
    {
        if (str_contains($message, "WEBVTT")) {
            $this->markTestSkipped("A file without WEBVTT throws in lenient mode too, and lenient mode repairs the header.");
        }

        $warnings = Subtitle::fromString($content, $format, new ReadOptions(lenient: true))->getParseWarnings();

        $rows = array_map(fn ($warning): array => [$warning->lineNumber, $warning->message], $warnings);
        $this->assertContains([$line, $message], $rows);
    }


    /**
     * @return array<string, array{SubRipStreamReader|WebVttStreamReader, string, int}>
     */
    public static function brokenStreams(): array
    {
        return [
            "SubRip"                => [new SubRipStreamReader(), self::SRT_BAD_TIME, 5],
            "WebVTT without WEBVTT" => [new WebVttStreamReader(), self::VTT_NO_HEADER, 3],
            "WebVTT header"         => [new WebVttStreamReader(), self::VTT_ARROW, 2],
            "WebVTT block"          => [new WebVttStreamReader(), self::VTT_BAD_BLOCK, 3],
        ];
    }


    #[DataProvider("brokenStreams")]
    public function testStreamReadersThrowWithTheSameLineNumber(SubRipStreamReader|WebVttStreamReader $reader, string $content, int $line): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        try {
            iterator_to_array($reader->read($stream));
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertSame($line, $exception->getLineNumber());
        }
    }
}
