<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Subtitle;

class SubRipParserTest extends TestCase
{
    public function testValidSrtFileParses()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/valid.srt"), SubRipParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testExceededHoursThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("timeString-string of at least one cue could not be parsed");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/exceeded_hours.srt"), SubRipParser::class);
    }


    public function testExceededMinutesThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("timeString-string of at least one cue could not be parsed");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/exceeded_minutes.srt"), SubRipParser::class);
    }


    public function testExceededSecondsThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("timeString-string of at least one cue could not be parsed");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/exceeded_seconds.srt"), SubRipParser::class);
    }


    public function testExceededMilliSecondAccuracyThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("timeString-string of at least one cue could not be parsed");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/exceeded_milli_accuracy.srt"), SubRipParser::class);
    }


    public function testMissingCueNumberThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("doesn't seem to have a cue-number");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/missing_cue_number.srt"), SubRipParser::class);
    }


    public function testMissingTextThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("doesn't have any text lines");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/missing_text.srt"), SubRipParser::class);
    }


    public function testMissingTimestampsThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("doesn't seem to have its timestamps");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/missing_timestamps.srt"), SubRipParser::class);
    }


    public function testSeveralEmptyLinesBetweenCuesStillSeparateCues(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:02,000\nFirst\n\n \n\n2\n00:00:03,000 --> 00:00:04,000\nSecond\n";

        $subtitle = Subtitle::parse($raw, SubRipParser::class);

        $this->assertSame(2, count($subtitle->getCues()));
        $this->assertSame("Second", $subtitle->getCues()[1]->getText());
    }


    public function testBlockWithOnlyACueNumberThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #1 doesn't seem to have its timestamps on its second line");
        Subtitle::parse("1\n00:00:01,000 --> 00:00:02,000\nFirst\n\n2", SubRipParser::class);
    }


    public function testLenientTimestampsParse(): void
    {
        $raw = "1\n0:00:01.5 --> 00:00:02,25\nDot, one hour digit, short milliseconds\n\n" .
               "2\n00:00:03.000 --> 01:02:03.004\nDots\n";

        $cues = Subtitle::parse($raw, SubRipParser::class)->getCues();

        $this->assertSame(1.5, $cues[0]->getStart());
        $this->assertSame(2.25, $cues[0]->getEnd());
        $this->assertSame(3.0, $cues[1]->getStart());
        $this->assertSame(3723.004, $cues[1]->getEnd());
    }


    public function testCoordinatesGoToTheFormatData(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000 X1:100 X2:600 Y1:40 Y2:80\nThe train leaves soon\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(4.0, $cue->getEnd());
        $this->assertSame(["The train leaves soon"], $cue->getLines());
        $this->assertSame(["coordinates" => ["x1" => 100, "x2" => 600, "y1" => 40, "y2" => 80]], $cue->getFormatData("srt"));
    }


    public function testIncompleteCoordinatesThrowException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("timeString-string of at least one cue could not be parsed");
        Subtitle::parse("1\n00:00:01,000 --> 00:00:04,000 X1:100 X2:600\nText\n", SubRipParser::class);
    }


    public function testAlignmentTagGoesToTheCueAlignment(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\an8}<i>The train leaves soon</i>\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(8, $cue->getAlignment());
        $this->assertSame(["<i>The train leaves soon</i>"], $cue->getLines());
    }


    public function testFirstAlignmentTagWinsAndAllAreRemoved(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\an4}Middle and horiz{\\an6}ontally left\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(4, $cue->getAlignment());
        $this->assertSame(["Middle and horizontally left"], $cue->getLines());
    }


    public function testLegacyAlignmentTagsAreConverted(): void
    {
        $expected = [1 => 1, 2 => 2, 3 => 3, 5 => 7, 6 => 8, 7 => 9, 9 => 4, 10 => 5, 11 => 6];
        foreach ($expected as $legacy => $alignment) {
            $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\a$legacy}Text\n";

            $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

            $this->assertSame($alignment, $cue->getAlignment(), "Legacy code $legacy");
            $this->assertSame(["Text"], $cue->getLines());
        }
    }


    public function testInvalidLegacyAlignmentStaysInTheText(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\a4}Text\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertNull($cue->getAlignment());
        $this->assertSame(["{\\a4}Text"], $cue->getLines());
    }


    public function testAssStyleTagsBecomeCoreMarkup(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\b1}bold{\\b0} {\\i1}italic{\\i0} {\\u1}under{\\u0} {\\s1}struck{\\s0}\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(["<b>bold</b> <i>italic</i> <u>under</u> <s>struck</s>"], $cue->getLines());
    }


    public function testUnclosedAssStyleTagsAreClosedAtTheEndOfTheCue(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\an8\\i1}one {\\b1}two\nthree{\\u0}\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(8, $cue->getAlignment());
        $this->assertSame(["<i>one <b>two", "three</b></i>"], $cue->getLines());
    }


    public function testUnknownOverrideTagsStayInTheText(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n{\\an8\\fad(200,200)}Sign {\\pos(10,20)}here {normal text}\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(8, $cue->getAlignment());
        $this->assertSame(["{\\fad(200,200)}Sign {\\pos(10,20)}here {normal text}"], $cue->getLines());
    }


    public function testCarriageReturnCarriageReturnLineFeedIsOneLineEnding(): void
    {
        $raw = "1\r\r\n00:00:01,000 --> 00:00:02,000\r\r\nFirst\r\r\nline\r\r\n\r\r\n2\r\r\n00:00:03,000 --> 00:00:04,000\r\r\nSecond\r\r\n";

        $cues = Subtitle::parse($raw, SubRipParser::class)->getCues();

        $this->assertSame(2, count($cues));
        $this->assertSame(["First", "line"], $cues[0]->getLines());
    }


    public function testTextOutsideTagsIsEscaped(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\nI <3 bread & jam\n<i>Salt & pepper</i> 2 > 1\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(["I &lt;3 bread &amp; jam", "<i>Salt &amp; pepper</i> 2 &gt; 1"], $cue->getLines());
    }


    public function testEntityInTheFileIsLiteralText(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\nThe sign says &amp; and &lt;b&gt;\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(["The sign says &amp;amp; and &amp;lt;b&amp;gt;"], $cue->getLines());
    }


    public function testTagsStayMarkup(): void
    {
        $raw = "1\n00:00:01,000 --> 00:00:04,000\n<B>bold</B> <font color=\"#00aa00\">green</font> <foo>unknown</foo>\n";

        $cue = Subtitle::parse($raw, SubRipParser::class)->getCues()[0];

        $this->assertSame(["<B>bold</B> <font color=\"#00aa00\">green</font> <foo>unknown</foo>"], $cue->getLines());
    }
}
