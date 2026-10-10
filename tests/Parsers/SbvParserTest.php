<?php

declare(strict_types=1);

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Format;
use SubtitleToolbox\ReadOptions;
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
        $subtitle = Subtitle::fromString("0:00:01.000,0:00:02.000\ncaf\xE9 & bread\n", Format::Sbv, new ReadOptions(encoding: "UTF-8"));

        $this->assertSame(["caf\xE9 &amp; bread"], $subtitle->getCues()[0]->getLines());
    }


    public function testExceededMinutesThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time \"0:60:04.000\" is not valid.");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/exceeded_minutes.sbv"), Format::Sbv);
    }


    public function testExceededSecondsThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time \"0:00:60.000\" is not valid.");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/exceeded_seconds.sbv"), Format::Sbv);
    }


    public function testMissingMilliDigitsThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("The time \"0:00:01.5\" is not valid.");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/missing_milli_digits.sbv"), Format::Sbv);
    }


    public function testSubRipTimestampsThrowException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #0 has no timing line on its first line");
        Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/srt_timestamps.sbv"), Format::Sbv);
    }


    public function testTextWithoutTimingLineAfterAnEmptyLineStaysInTheCueBefore(): void
    {
        $cues = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/missing_timestamps.sbv"), Format::Sbv)->getCues();

        $this->assertCount(1, $cues);
        $this->assertSame(["Hello world", "Second cue", "on two lines"], $cues[0]->getLines());
    }


    public function testStrictModeStartsANewCueAtEveryTimingLine(): void
    {
        $cues = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/lenient/missing_empty_line.sbv"), Format::Sbv)->getCues();

        $this->assertSame(
            [[1.0, 2.5, ["The wind is cold today."]], [3.0, 5.0, ["Snow falls in the hills."]], [5.5, 7.0, ["The roads are closed."]]],
            array_map(fn ($cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $cues)
        );
    }


    public function testLastCueWithoutTextHasNoLines(): void
    {
        $cues = Subtitle::fromString(file_get_contents(__DIR__ . "/../files/sbv/missing_text.sbv"), Format::Sbv)->getCues();

        $this->assertSame([5.0, 7.25, []], [$cues[1]->getStart(), $cues[1]->getEnd(), $cues[1]->getLines()]);
    }


    public static function inputsWithoutCues(): array
    {
        return [
            "empty"               => [""],
            "BOM only"            => ["\xEF\xBB\xBF"],
            "whitespace only"     => [" \n\t\r\n\n  "],
            "BOM and empty lines" => ["\xEF\xBB\xBF\r\n\r\n"],
        ];
    }


    #[DataProvider("inputsWithoutCues")]
    public function testAFileWithoutCuesReadsAsZeroCues(string $content): void
    {
        foreach ([new ReadOptions(), new ReadOptions(lenient: true)] as $options) {
            $subtitle = Subtitle::fromString($content, Format::Sbv, $options);

            $this->assertSame([], $subtitle->getCues());
            $this->assertSame([], $subtitle->getParseWarnings());
        }
    }


    /**
     * Each case holds a timing line, its start and end, and whether strict mode reads it.
     */
    public static function timingLineForms(): array
    {
        return [
            "SBV form"              => ["0:00:07.980,0:00:11.300", 7.98, 11.3, true],
            "dot between the times" => ["0:00:07.980.0:00:11.300", 7.98, 11.3, false],
            "commas everywhere"     => ["0:00:07,980,0:00:11,300", 7.98, 11.3, false],
            "short fractions"       => ["0:00:07.98,0:00:11.3", 7.98, 11.3, false],
        ];
    }


    #[DataProvider("timingLineForms")]
    public function testStrictModeReadsOnlyTheSbvTimingLine(string $timingLine, float $start, float $end, bool $strictReads): void
    {
        if (!$strictReads) {
            $this->expectException(ParsingException::class);
        }

        $cues = Subtitle::fromString("$timingLine\nText\n", Format::Sbv)->getCues();
        $this->assertSame([$start, $end, ["Text"]], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
    }


    #[DataProvider("timingLineForms")]
    public function testLenientModeReadsOtherSeparatorsAndFractionsAndWarns(string $timingLine, float $start, float $end, bool $strictReads): void
    {
        $subtitle = Subtitle::fromString("$timingLine\nText\n\n0:00:20.000,0:00:21.000\nMore\n", Format::Sbv, new ReadOptions(lenient: true));

        $cues = $subtitle->getCues();
        $this->assertCount(2, $cues);
        $this->assertSame([$start, $end, ["Text"]], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getLines()]);
        $this->assertCount($strictReads ? 0 : 1, $subtitle->getParseWarnings());
        $this->assertSame("0:00:07.980,0:00:11.300\nText\n\n0:00:20.000,0:00:21.000\nMore\n", $subtitle->toString(Format::Sbv));
    }


    public function testLenientModeRejectsLooseTimesWithMinutesOrSecondsAbove59(): void
    {
        $subtitle = Subtitle::fromString("0:00:61.5,0:01:02.0\nText\n", Format::Sbv, new ReadOptions(lenient: true));

        $this->assertSame([], $subtitle->getCues());
        $this->assertSame("The time \"0:00:61.5\" is not valid.", $subtitle->getParseWarnings()[0]->message);
    }
}
