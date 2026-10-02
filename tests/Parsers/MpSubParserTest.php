<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Formatters\MpSubFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class MpSubParserTest extends TestCase
{
    private const TIME_FILE   = __DIR__ . "/../files/mpsub/real/mplayer_doc_time.sub";
    private const FRAMES_FILE = __DIR__ . "/../files/mpsub/real/mplayer_doc_frames.sub";


    public function testTimeBasedSampleFileParses(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::TIME_FILE), MpSubParser::class);
        $cues     = $subtitle->getCues();

        $this->assertSame(8, count($cues));
        $this->assertSame(2.0, $cues[0]->getStart());
        $this->assertSame(5.0, $cues[0]->getEnd());
        $this->assertSame(["The ferry leaves at nine."], $cues[0]->getLines());
        $this->assertSame(20.25, $cues[5]->getStart());
        $this->assertSame(22.0, $cues[5]->getEnd());
        $this->assertSame(27.0, $cues[7]->getStart());
        $this->assertSame(29.0, $cues[7]->getEnd());
        $this->assertSame(["Let's go."], $cues[7]->getLines());
        $this->assertSame([], $subtitle->getErrors());
    }


    public function testTimeBasedSampleFileHeadersGoToMetadataAndFormatData(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::TIME_FILE), MpSubParser::class);

        $this->assertSame(
            [Subtitle::METADATA_TITLE => "Harbour walk (sample)", Subtitle::METADATA_AUTHOR => "Subtitle Toolbox"],
            $subtitle->getAllMetadata()
        );
        $this->assertSame(
            [
                "FILE" => "1048576,0123456789abcdef0123456789abcdef",
                "TYPE" => "VIDEO",
                "NOTE" => "Sample file written after the MPlayer format notes",
            ],
            $subtitle->getFormatData("mpsub")
        );
    }


    public function testTimeBasedSampleFileRoundTrips(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::TIME_FILE), MpSubParser::class);
        $output   = $subtitle->format(MpSubFormatter::class);
        $reparsed = Subtitle::parse($output, MpSubParser::class);

        $this->assertStringStartsWith("\xEF\xBB\xBFTITLE=Harbour walk (sample)\nAUTHOR=Subtitle Toolbox\n", $output);
        $this->assertEquals($subtitle, $reparsed);
        $this->assertSame($output, $reparsed->format(MpSubFormatter::class));
    }


    public function testFrameBasedSampleFileParses(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::FRAMES_FILE), MpSubParser::class);
        $cues     = $subtitle->getCues();

        $this->assertSame(3, count($cues));
        $this->assertSame(2.0, $cues[0]->getStart());
        $this->assertSame(3.5, $cues[0]->getEnd());
        $this->assertSame(["Two seconds in,", "shown for one and a half seconds"], $cues[0]->getLines());
        $this->assertSame(3.54, $cues[1]->getStart());
        $this->assertSame(7.54, $cues[1]->getEnd());
        $this->assertSame(8.04, $cues[2]->getStart());
        $this->assertSame(11.04, $cues[2]->getEnd());
        $this->assertSame(["Half a second later,", "shown for three seconds"], $cues[2]->getLines());
        $this->assertSame([Subtitle::METADATA_TITLE => "Harbour walk, frame based (sample)"], $subtitle->getAllMetadata());
        $this->assertSame([], $subtitle->getFormatData("mpsub"));
    }


    public function testFrameBasedSampleFileRoundTripsWithinOneFrame(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::FRAMES_FILE), MpSubParser::class);
        $output   = $subtitle->format(MpSubFormatter::class, [MpSubFormatter::OPTION_FRAME_RATE => 25]);
        $reparsed = Subtitle::parse($output, MpSubParser::class);

        $this->assertStringContainsString("\nFORMAT=25\n", $output);
        $this->assertSame(3, count($reparsed->getCues()));
        foreach ($subtitle->getCues() as $idx => $cue) {
            $this->assertEqualsWithDelta($cue->getStart(), $reparsed->getCues()[$idx]->getStart(), 0.021);
            $this->assertEqualsWithDelta($cue->getEnd(), $reparsed->getCues()[$idx]->getEnd(), 0.021);
            $this->assertSame($cue->getLines(), $reparsed->getCues()[$idx]->getLines());
        }
    }


    public function testFrameBasedFileParsesAndRoundTripsByteForByte(): void
    {
        $raw      = file_get_contents(__DIR__ . "/../files/mpsub/frames.mpsub");
        $subtitle = Subtitle::parse($raw, MpSubParser::class);
        $cues     = $subtitle->getCues();

        $this->assertSame(0.0, $cues[0]->getStart());
        $this->assertSame(2.0, $cues[0]->getEnd());
        $this->assertSame(3.0, $cues[1]->getStart());
        $this->assertSame(6.0, $cues[1]->getEnd());
        $this->assertSame("Big Buck Bunny", $subtitle->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("Jane Doe", $subtitle->getMetadata(Subtitle::METADATA_AUTHOR));
        $this->assertSame(["TYPE" => "VIDEO"], $subtitle->getFormatData("mpsub"));

        $expected = "\xEF\xBB\xBF" . str_replace(
            "FORMAT=25\n",
            "FORMAT=25\nNOTE=Created with the PHP Subtitle Toolbox (https://github.com/yama6a/subtitle-toolbox)\n",
            $raw
        );
        $this->assertSame($expected, $subtitle->format(MpSubFormatter::class, [MpSubFormatter::OPTION_FRAME_RATE => 25]));
    }


    public function testFormatterOutputParsesBackToTheSameCues(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/mpsub/valid.mpsub"), MpSubParser::class);

        $this->assertSame(6, count($subtitle->getCues()));
        $this->assertSame(0.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame(0.456, $subtitle->getCues()[0]->getEnd());
        $this->assertSame(3599999.999, $subtitle->getCues()[5]->getEnd());
        $this->assertSame([], $subtitle->getAllMetadata());
        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/mpsub/valid.mpsub"),
            $subtitle->format(MpSubFormatter::class)
        );
    }


    public function testOverlappingCuesWriteANegativeWaitThatParsesBack(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 4, "First"))
            ->addCue(new SubtitleCue(2.5, 5, "Second"));

        $output = $subtitle->format(MpSubFormatter::class);
        $this->assertStringContainsString("\n-1.5 2.5\nSecond\n", $output);

        $reparsed = Subtitle::parse($output, MpSubParser::class);
        $this->assertSame(2.5, $reparsed->getCues()[1]->getStart());
        $this->assertSame(5.0, $reparsed->getCues()[1]->getEnd());
    }


    public function testWindowsLineEndingsAndMissingTrailingNewlineAreAccepted(): void
    {
        $subtitle = Subtitle::parse("FORMAT=TIME\r\n\r\n1 2\r\nHello\r\n\r\n\r\n0.5 1\r\nWorld", MpSubParser::class);

        $this->assertSame(2, count($subtitle->getCues()));
        $this->assertSame(3.5, $subtitle->getCues()[1]->getStart());
        $this->assertSame("World", $subtitle->getCues()[1]->getText());
    }


    public function testPlainTextIsEscapedAndRoundTrips(): void
    {
        $raw      = "TITLE=Tom & Jerry <draft>\nFORMAT=TIME\n\n1 1\nI <3 bread & jam\n";
        $subtitle = Subtitle::parse($raw, MpSubParser::class);

        $this->assertSame(["I &lt;3 bread &amp; jam"], $subtitle->getCues()[0]->getLines());
        $this->assertSame("Tom & Jerry <draft>", $subtitle->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertStringContainsString(
            "TITLE=Tom & Jerry <draft>\n",
            $subtitle->format(MpSubFormatter::class)
        );
        $this->assertStringContainsString(
            "\n1 1\nI <3 bread & jam\n",
            $subtitle->format(MpSubFormatter::class)
        );
    }


    public function testLatin1TextKeepsItsBytes(): void
    {
        $subtitle = Subtitle::parse("FORMAT=TIME\n\n1 1\ncaf\xE9 & tea\n", MpSubParser::class);

        $this->assertSame(["caf\xE9 &amp; tea"], $subtitle->getCues()[0]->getLines());
    }


    public function testFrameRateUsesOnlyTheLeadingInteger(): void
    {
        $subtitle = Subtitle::parse("FORMAT=29.97\n\n29 58\nHello\n", MpSubParser::class);

        $this->assertSame(1.0, $subtitle->getCues()[0]->getStart());
        $this->assertSame(3.0, $subtitle->getCues()[0]->getEnd());
    }


    public function testUnknownLineThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Line 3 is neither a header, a comment nor a timing line: 00:00:01,000");
        Subtitle::parse("FORMAT=TIME\n\n00:00:01,000\nHello\n", MpSubParser::class);
    }


    public function testNegativeDurationThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The cue on line 3 has a negative duration: 1 -2");
        Subtitle::parse("FORMAT=TIME\n\n1 -2\nHello\n", MpSubParser::class);
    }


    public function testCueWithoutTextThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The cue that ends on line 3 doesn't have any text lines!");
        Subtitle::parse("FORMAT=TIME\n\n1 2\n\n3 4\nHello\n", MpSubParser::class);
    }


    public function testInvalidFormatThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Line 1 has an unknown FORMAT value: FRAMES");
        Subtitle::parse("FORMAT=FRAMES\n\n1 2\nHello\n", MpSubParser::class);
    }


    public function testZeroFrameRateThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Line 1 has an invalid frame rate: 0");
        Subtitle::parse("FORMAT=0\n\n1 2\nHello\n", MpSubParser::class);
    }
}
