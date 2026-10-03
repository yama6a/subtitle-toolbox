<?php

namespace SubtitleToolbox\Formatters;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Image\CueImage;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class PlainTextFormatterTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/";


    private function walk(): Subtitle
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, ["<v Mara><b>Hello</b>", "world."]));
        $subtitle->addCue(new SubtitleCue(2.5, 4, "Where are you  going?"));
        $subtitle->addCue(new SubtitleCue(6, 7, ["<font color=\"#ff0000\">Home</font> &amp; <00:00:06.500>bed.", "  "]));
        $subtitle->addCue(new SubtitleCue(3725, 3727, "Fish &lt;3"));

        return $subtitle;
    }


    public static function options(): array
    {
        return [
            "defaults"           => [[], "Hello world. Where are you going?\n\nHome & bed.\n\nFish <3\n"],
            "lines kept"         => [[PlainTextFormatter::OPTION_JOIN_LINES => false], "Hello\nworld. Where are you going?\n\nHome & bed.\n\nFish <3\n"],
            "one cue per line"   => [[PlainTextFormatter::OPTION_JOIN_CUES => false], "Hello world.\nWhere are you going?\n\nHome & bed.\n\nFish <3\n"],
            "gap of 2.5 s"       => [[PlainTextFormatter::OPTION_PARAGRAPH_GAP => 2.5], "Hello world. Where are you going? Home & bed.\n\nFish <3\n"],
            "gap of 0.5 s"       => [[PlainTextFormatter::OPTION_PARAGRAPH_GAP => 0.5], "Hello world.\n\nWhere are you going?\n\nHome & bed.\n\nFish <3\n"],
            "no paragraphs"      => [[PlainTextFormatter::OPTION_PARAGRAPH_GAP => INF], "Hello world. Where are you going? Home & bed. Fish <3\n"],
            "with times"         => [[PlainTextFormatter::OPTION_WITH_TIMES => true],
                                     "[00:00:01] Hello world. Where are you going?\n\n[00:00:06] Home & bed.\n\n[01:02:05] Fish <3\n"],
            "CR LF"              => [[PlainTextFormatter::OPTION_LINE_ENDING => "\r\n", PlainTextFormatter::OPTION_PARAGRAPH_GAP => INF],
                                     "Hello world. Where are you going? Home & bed. Fish <3\r\n"],
        ];
    }


    #[DataProvider("options")]
    public function testFormatsWithOptions(array $options, string $expected): void
    {
        $this->assertSame($expected, $this->walk()->toString(Format::PlainText, $options));
    }


    public function testMeasuresTheGapFromTheLatestEnd(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(0, 10, "Long"));
        $subtitle->addCue(new SubtitleCue(1, 2, "Short"));
        $subtitle->addCue(new SubtitleCue(11, 12, "Overlapped"));

        $this->assertSame("Long Short Overlapped\n", $subtitle->toString(Format::PlainText));
    }


    public function testSkipsCuesWithoutTextAndWritesNothingForNoCues(): void
    {
        $subtitle = new Subtitle();
        $subtitle->addCue(new SubtitleCue(1, 2, "<i></i>"));
        $subtitle->addCue((new CueImage("png", 0, 0, 1, 1, 720, 576))->toCue(new SubtitleCue(3, 4)));

        $this->assertSame("", (new Subtitle())->toString(Format::PlainText));
        $this->assertSame("", $subtitle->toString(Format::PlainText, [PlainTextFormatter::OPTION_SKIP_IMAGE_CUES => true]));
    }


    public function testThrowsForAParagraphGapThatIsNoNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The option paragraphGap must be a number of seconds.");

        $this->walk()->toString(Format::PlainText, [PlainTextFormatter::OPTION_PARAGRAPH_GAP => "2"]);
    }


    public static function realFiles(): array
    {
        return [
            "defaults"   => ["youtube_studio_lf.txt", []],
            "with times" => ["youtube_studio_lf_with_times.txt", [PlainTextFormatter::OPTION_WITH_TIMES => true, PlainTextFormatter::OPTION_JOIN_CUES => false]],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileGivesTheExpectedTranscript(string $fileName, array $options): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "sbv/real/youtube_studio_lf.sbv"), Format::Sbv);

        $this->assertSame(file_get_contents(self::DIR . "plaintext/real/$fileName"), $subtitle->toString(Format::PlainText, $options));
    }
}
