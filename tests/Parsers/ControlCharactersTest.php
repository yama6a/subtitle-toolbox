<?php

declare(strict_types=1);

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ParseWarning;
use SubtitleToolbox\ParseWarningAction;
use SubtitleToolbox\ReadOptions;
use SubtitleToolbox\Streaming\SubRipStreamReader;
use SubtitleToolbox\Streaming\WebVttStreamReader;
use SubtitleToolbox\Subtitle;

class ControlCharactersTest extends TestCase
{
    /**
     * Each case holds the format and a file with "%s" where the text of the first cue goes, on line 4.
     *
     * @return array<string, array{Format, string}>
     */
    public static function formats(): array
    {
        return [
            "SubRip"    => [Format::SubRip, "\n1\n00:00:01,000 --> 00:00:02,000\n%s\n\n2\n00:00:03,000 --> 00:00:04,000\nLast cue.\n"],
            "WebVTT"    => [Format::WebVtt, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n%s\n\n00:00:03.000 --> 00:00:04.000\nLast cue.\n"],
            "SBV"       => [Format::Sbv, "\n\n0:00:01.000,0:00:02.000\n%s\n\n0:00:03.000,0:00:04.000\nLast cue.\n"],
            "SubViewer" => [Format::SubViewer, "[INFORMATION]\n[END INFORMATION]\n00:00:01.00,00:00:02.00\n%s\n\n00:00:03.00,00:00:04.00\nLast cue.\n"],
            "ASS"       => [Format::Ass, "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n\n" .
                                         "Dialogue: 0,0:00:01.00,0:00:02.00,Default,,0,0,0,,%s\nDialogue: 0,0:00:03.00,0:00:04.00,Default,,0,0,0,,Last cue.\n"],
        ];
    }


    /**
     * @return array<string, array{Format, string, string}>
     */
    public static function controlCharacters(): array
    {
        $cases = [];
        foreach (self::formats() as $name => [$format, $file]) {
            foreach (["form feed" => "\f", "vertical tab" => "\x0B", "escape" => "\x1B", "DEL" => "\x7F", "NUL" => "\0"] as $character => $byte) {
                if ($format !== Format::WebVtt || $byte !== "\0") {
                    $cases["$name, $character"] = [$format, $file, $byte];
                }
            }
        }

        return $cases;
    }


    #[DataProvider("controlCharacters")]
    public function testStrictModeRemovesControlCharactersWithoutWarningAndThrowsForNul(Format $format, string $file, string $byte): void
    {
        if ($byte === "\0") {
            $this->expectException(ParsingException::class);
            $this->expectExceptionMessage("Line 4 has a NUL character. Check the encoding of the file. (line 4)");
        }

        $subtitle = Subtitle::fromString(sprintf($file, "The fer{$byte}ry leaves."), $format);

        $this->assertSame("The ferry leaves.", $subtitle->getCues()[0]->getText());
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    public function testStrictStreamReaderThrowsForANul(): void
    {
        $stream = fopen("php://memory", "w+b");
        fwrite($stream, sprintf(self::formats()["SubRip"][1], "The fer\0ry leaves."));
        rewind($stream);

        try {
            iterator_to_array((new SubRipStreamReader())->read($stream));
            $this->fail("No exception");
        } catch (ParsingException $exception) {
            $this->assertSame(4, $exception->getLineNumber());
        }
    }


    #[DataProvider("controlCharacters")]
    public function testLenientModeRemovesControlCharactersWithOneWarning(Format $format, string $file, string $byte): void
    {
        $subtitle = Subtitle::fromString(sprintf($file, "The fer{$byte}ry {$byte}leaves."), $format, new ReadOptions(lenient: true));

        $this->assertSame("The ferry leaves.", $subtitle->getCues()[0]->getText());
        $this->assertEquals(
            [new ParseWarning("The file has control characters, the first on line 4. The parser removed them.", 4, null, [], ParseWarningAction::Repaired)],
            $subtitle->getParseWarnings()
        );
    }


    #[DataProvider("formats")]
    public function testFormatCharactersAndTabsStay(Format $format, string $file): void
    {
        $text     = "\u{200E}The\u{200D}ferry\u{200F} leaves.";
        $subtitle = Subtitle::fromString(sprintf($file, $text), $format, new ReadOptions(lenient: true));

        $this->assertSame($text, $subtitle->getCues()[0]->getText());
        $this->assertSame([], $subtitle->getParseWarnings());
    }


    #[DataProvider("formats")]
    public function testATrailingCtrlZIsIgnoredInBothModes(Format $format, string $file): void
    {
        foreach (["\x1A", "\x1A\x1A\r\n", "\r\n\x1A"] as $end) {
            foreach ([false, true] as $lenient) {
                $subtitle = Subtitle::fromString(sprintf($file, "The ferry leaves.") . $end, $format, new ReadOptions(lenient: $lenient));

                $this->assertSame(["The ferry leaves.", "Last cue."], array_map(fn ($cue): string => $cue->getText(), $subtitle->getCues()));
                $this->assertSame([], $subtitle->getParseWarnings());
            }
        }
    }


    public function testWebVttReplacesNulInBothModesWithoutWarning(): void
    {
        foreach ([false, true] as $lenient) {
            $subtitle = Subtitle::fromString("WEBVTT\n\n00:01.000 --> 00:02.000\nH\0i\n", Format::WebVtt, new ReadOptions(lenient: $lenient));

            $this->assertSame("H\u{FFFD}i", $subtitle->getCues()[0]->getText());
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }


    /**
     * @return array<string, array{SubRipStreamReader|WebVttStreamReader, Format, string}>
     */
    public static function streams(): array
    {
        $formats = self::formats();
        $file    = fn (Format $format): string => sprintf($formats[$format === Format::SubRip ? "SubRip" : "WebVTT"][1], "The\f fer\0ry\x7F.") . "\r\n\x1A";

        return [
            "SubRip" => [new SubRipStreamReader(new ReadOptions(lenient: true)), Format::SubRip, $file(Format::SubRip)],
            "WebVTT" => [new WebVttStreamReader(new ReadOptions(lenient: true)), Format::WebVtt, $file(Format::WebVtt)],
        ];
    }


    #[DataProvider("streams")]
    public function testStreamReadersGiveTheCuesAndWarningsOfTheParser(SubRipStreamReader|WebVttStreamReader $reader, Format $format, string $content): void
    {
        $subtitle = Subtitle::fromString($content, $format, new ReadOptions(lenient: true));
        $stream   = fopen("php://memory", "w+b");
        fwrite($stream, $content);
        rewind($stream);

        $this->assertEquals($subtitle->getCues(), iterator_to_array($reader->read($stream)));
        $this->assertEquals($subtitle->getParseWarnings(), $reader->getWarnings());
        $this->assertCount(1, $reader->getWarnings());
    }
}
