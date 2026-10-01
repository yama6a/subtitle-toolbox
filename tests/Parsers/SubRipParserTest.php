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
}
