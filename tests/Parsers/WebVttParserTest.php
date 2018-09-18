<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;

class WebVttParserTest extends TestCase
{
    public function testValidVttFileParses()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"), WebVttParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testStripsNotesAndStyles()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/vtt/with_styles_and_notes.vtt"), WebVttParser::class
        );

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testWorksWithMissingHours()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/vtt/missing_hours.vtt"), WebVttParser::class
        );

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testMissingEmptyLineAfterWebvttHeader()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("No empty line found after the first line containing WEBVTT");
        Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/vtt/no_empty_line_after_webvtt_header.vtt"), WebVttParser::class
        );
    }


    public function testMissingWebvttHeaderThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("file doesn't start with the string WEBVTT!");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/missing_webvtt_header.vtt"), WebVttParser::class);
    }


    public function testExceededHoursThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("time-string of at least one cue could not be parsed");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/exceeded_hours.vtt"), WebVttParser::class);
    }


    public function testExceededMinutesThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("time-string of at least one cue could not be parsed");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/exceeded_minutes.vtt"), WebVttParser::class);
    }


    public function testExceededSecondsThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("time-string of at least one cue could not be parsed");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/exceeded_seconds.vtt"), WebVttParser::class);
    }


    public function testExceededMilliSecondAccuracyThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("time-string of at least one cue could not be parsed");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/exceeded_milli_accuracy.vtt"), WebVttParser::class);
    }


    public function testMissingCueNumberWorksLikeWithNumbers()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/vtt/missing_cue_number.vtt"), WebVttParser::class
        );
        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/valid.srt"),
            $subtitle->format(SubRipFormatter::class)
        );
    }


    public function testMissingTextThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("doesn't have any text lines");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/missing_text.vtt"), WebVttParser::class);
    }


    public function testMissingTimestampsThrowsException()
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("doesn't match anything that we can parse");
        Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/missing_timestamps.vtt"), WebVttParser::class);
    }
}
