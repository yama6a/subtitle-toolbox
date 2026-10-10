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
            "SubRip without cue number" => [Format::SubRip, self::SRT_NO_NUMBER, 5, "Block #1 has no cue number on its first line. The line is \"x\"."],
            "SubRip with a bad time"    => [Format::SubRip, self::SRT_BAD_TIME, 6, "The time \"00:00:0x,000\" is not valid."],
            "SubRip without timing line" => [Format::SubRip, "1\n00:00:01,000 --> 00:00:02,000\nThe ferry leaves.\n\n2\n00:00:03,000 => 00:00:04,000\nIt is late.\n", 6,
                                             "Block #1 has no timing line on its second line. The line is \"00:00:03,000 => 00:00:04,000\"."],
            "SubRip with text after the end time" => [Format::SubRip, "1\n00:00:01,000 --> 00:00:02,000 x\nThe ferry leaves.\n", 2,
                                                      "The text \"x\" after the end time is not valid."],
            "SBV without timing line"   => [Format::Sbv, "0:00:01.000,0:00:02.000\nThe ferry leaves.\n\n0:00:03.000\nIt is late.\n", 4,
                                            "Block #1 has no timing line on its first line. The line is \"0:00:03.000\"."],
            "SBV with a bad time"       => [Format::Sbv, "0:00:01.000,0:00:02.000\nThe ferry leaves.\n\n0:00:0x.000,0:00:04.000\nIt is late.\n", 4,
                                            "The time \"0:00:0x.000\" is not valid."],
            "SBV with 100000 hours"     => [Format::Sbv, "0:00:01.000,0:00:02.000\nThe ferry leaves.\n\n100000:00:03.000,100000:00:04.000\nIt is late.\n", 4,
                                            "The time \"100000:00:03.000\" is not below 100000 hours."],
            "WebVTT without WEBVTT"     => [Format::WebVtt, self::VTT_NO_HEADER, 3, "The file does not start with WEBVTT."],
            "WebVTT header with a cue"  => [Format::WebVtt, self::VTT_ARROW, 2, "The WEBVTT header has no empty line before the first cue."],
            "WebVTT unknown block"      => [Format::WebVtt, self::VTT_BAD_BLOCK, 3, "Block #1 is not a WebVTT cue, comment, style or region. The line is \"It is late.\"."],
            "WebVTT with a bad time"    => [Format::WebVtt, self::VTT_BAD_TIME, 6, "The time \"00:00:0x.000\" is not valid."],
            "WebVTT with a bad end time after an identifier" => [Format::WebVtt, "WEBVTT\n\nintro\n00:00:01.000 --> 00:00:0x.000\nThe ferry leaves.\n", 4,
                                                                 "The time \"00:00:0x.000\" is not valid."],
            "WebVTT with a bad start time after an identifier" => [Format::WebVtt, "WEBVTT\n\nintro\n00:00:0x.000 --> 00:00:02.000\nThe ferry leaves.\n", 4,
                                                                   "The time \"00:00:0x.000\" is not valid."],
            "WebVTT with 100000 hours"  => [Format::WebVtt, "WEBVTT\n\n100000:00:01.000 --> 100000:00:02.000\nThe ferry leaves.\n", 3,
                                            "The time \"100000:00:01.000\" is not below 100000 hours."],
            "WebVTT with a word timestamp of 100000 hours" => [Format::WebVtt, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nThe ferry\nleaves <100000:00:01.500>now.\n", 5,
                                                               "The time \"100000:00:01.500\" is not below 100000 hours."],
            "SAMI without Start"        => [Format::Sami, "<SAMI>\n<BODY>\n<SYNC>The ferry leaves.\n</BODY>\n</SAMI>\n", 3,
                                            "SYNC tag 1 has no valid Start attribute."],
        ];
    }


    public function testTheMessageCutsALongLineTo60Characters(): void
    {
        $this->expectExceptionMessage('The line is "' . str_repeat("é", 57) . '...". (line 2)');

        Subtitle::fromString("1\n" . str_repeat("é", 80) . "\nThe ferry leaves.\n", Format::SubRip);
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
        if (str_contains($message, "after the end time")) {
            $this->markTestSkipped("Lenient mode ignores the text after the end time with another message.");
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
            "SubRip"                => [new SubRipStreamReader(), self::SRT_BAD_TIME, 6],
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
