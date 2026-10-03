<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;

class SbvParserTest extends TestCase
{
    public function testValidSbvFileParses(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/valid.sbv"), Format::Sbv);

        $this->assertSame(5, count($subtitle->getCues()));
        $this->assertSame(3661.0, $subtitle->getCues()[3]->getStart());
        $this->assertSame(7200.8, $subtitle->getCues()[3]->getEnd());
        $this->assertSame(["Full timestamp", "on two lines"], $subtitle->getCues()[3]->getLines());
        $this->assertSame(359999.999, $subtitle->getCues()[4]->getEnd());
    }


    public function testTwoDigitHoursParse(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/two_digit_hours.sbv"), Format::Sbv);

        $this->assertSame(1.5, $subtitle->getCues()[0]->getStart());
        $this->assertSame(3605.0, $subtitle->getCues()[1]->getStart());
        $this->assertSame(3607.25, $subtitle->getCues()[1]->getEnd());
        $this->assertSame(["Second cue", "on two lines"], $subtitle->getCues()[1]->getLines());
    }


    public function testUtf8BomAndWindowsLineEndingsAreAccepted(): void
    {
        $raw = "\xEF\xBB\xBF" . str_replace("\n", "\r\n", file_get_contents(__DIR__ . "/../files/sbv/valid.sbv"));

        $subtitle = Subtitle::fromString($raw, Format::Sbv);

        $this->assertSame(5, count($subtitle->getCues()));
        $this->assertSame("Only milliseconds", $subtitle->getCues()[0]->getText());
    }


    public function testSeveralEmptyLinesBetweenCuesStillSeparateCues(): void
    {
        $raw = "\n\n0:00:01.000,0:00:02.000\nFirst\n\n \n\n0:00:03.000,0:00:04.000\nSecond\n\n\n";

        $subtitle = Subtitle::fromString($raw, Format::Sbv);

        $this->assertSame(2, count($subtitle->getCues()));
        $this->assertSame("Second", $subtitle->getCues()[1]->getText());
    }


    public function testThreeDigitHoursParseAndRoundTrip(): void
    {
        $raw      = file_get_contents(__DIR__ . "/../files/sbv/three_digit_hours.sbv");
        $subtitle = Subtitle::fromString($raw, Format::Sbv);

        $this->assertSame(360001.5, $subtitle->getCues()[0]->getStart());
        $this->assertSame($raw, $subtitle->toString(Format::Sbv));
    }


    public function testTextIsStoredWithMarkupCharactersEscaped(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/special_characters.sbv"), Format::Sbv);

        $this->assertSame(["I &lt;3 bread &amp; jam"], $subtitle->getCues()[0]->getLines());
        $this->assertSame(
            ["&lt;b&gt;is not bold&lt;/b&gt; in SBV", "The bakery &amp;amp; the station"],
            $subtitle->getCues()[1]->getLines()
        );
    }


    public function testTextWithMarkupCharactersRoundTrips(): void
    {
        $raw = file_get_contents(__DIR__ . "/../files/sbv/special_characters.sbv");

        $this->assertSame($raw, Subtitle::fromString($raw, Format::Sbv)->toString(Format::Sbv));
    }


    public function testLatin1TextKeepsItsBytes(): void
    {
        $subtitle = Subtitle::fromString("0:00:01.000,0:00:02.000\ncaf\xE9 & bread\n", Format::Sbv);

        $this->assertSame(["caf\xE9 &amp; bread"], $subtitle->getCues()[0]->getLines());
    }


    public function testExceededMinutesThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("timeString-string of at least one cue could not be parsed: 0:60:04.000");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/exceeded_minutes.sbv"), Format::Sbv);
    }


    public function testExceededSecondsThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("timeString-string of at least one cue could not be parsed: 0:00:60.000");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/exceeded_seconds.sbv"), Format::Sbv);
    }


    public function testMissingMilliDigitsThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("timeString-string of at least one cue could not be parsed: 0:00:01.5");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/missing_milli_digits.sbv"), Format::Sbv);
    }


    public function testSubRipTimestampsThrowException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #0 doesn't seem to have its timestamps on its first line");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/srt_timestamps.sbv"), Format::Sbv);
    }


    public function testMissingTimestampsThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #1 doesn't seem to have its timestamps on its first line");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/missing_timestamps.sbv"), Format::Sbv);
    }


    public function testMissingTextThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #1 doesn't have any text lines");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/missing_text.sbv"), Format::Sbv);
    }
}
