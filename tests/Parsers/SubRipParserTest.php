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
}
