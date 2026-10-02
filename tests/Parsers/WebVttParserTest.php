<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Parsers\WebVttParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class WebVttParserTest extends TestCase
{
    public function testValidVttFileParses()
    {
        $subtitle = Subtitle::parse(file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"), WebVttParser::class);

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"),
            $subtitle->format(WebVttFormatter::class)
        );
    }


    public function testKeepsNotesAndStyles()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/vtt/with_styles_and_notes.vtt"), WebVttParser::class
        );

        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/vtt/with_styles_and_notes_formatted.vtt"),
            $subtitle->format(WebVttFormatter::class)
        );
    }


    public function testWorksWithMissingHours()
    {
        $subtitle = Subtitle::parse(
            file_get_contents(__DIR__ . "/../files/vtt/missing_hours.vtt"), WebVttParser::class
        );

        $this->assertSame($this->validWithHeaderText(), $subtitle->format(WebVttFormatter::class));
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
        $this->assertSame($this->validWithHeaderText(), $subtitle->format(WebVttFormatter::class));
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


    public function testSeveralEmptyLinesBetweenCuesStillSeparateCues(): void
    {
        $raw = "WEBVTT\n\n\n00:01.000 --> 00:02.000\nFirst\n\n \n\n00:03.000 --> 00:04.000\nSecond\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);

        $this->assertSame(2, count($subtitle->getCues()));
        $this->assertSame("Second", $subtitle->getCues()[1]->getText());
    }


    public function testSingleLineBlockThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #1 doesn't match anything");
        Subtitle::parse("WEBVTT\n\nstray line", WebVttParser::class);
    }


    public function testCueWithOnlyATimestampThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("Block #1 doesn't have any text lines");
        Subtitle::parse("WEBVTT\n\n00:01.000 --> 00:02.000", WebVttParser::class);
    }


    public function testLowercaseNoteAndStyleBlocksAreSkipped(): void
    {
        $raw = "WEBVTT\n\nnote a comment\n\nstyle\n::cue {}\n\n00:01.000 --> 00:02.000\nText\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);

        $this->assertSame(1, count($subtitle->getCues()));
        $this->assertSame("Text", $subtitle->getCues()[0]->getText());
    }


    public function testIssueExampleParses(): void
    {
        $raw = "WEBVTT Episode 1\nKind: captions\nLanguage: en\n\n"
               . "REGION\nid:fred\nwidth:40%\nlines:3\nregionanchor:0%,100%\nviewportanchor:10%,90%\nscroll:up\n\n"
               . "NOTE Translated by Jane Doe\n\n"
               . "intro\n00:00:01.000 --> 00:00:04.000 region:fred align:left line:85%\n"
               . "<v Fred>Hi, I am Fred &amp; this is <c.yellow>Bob</c></v>\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);
        $cue      = $subtitle->getCues()[0];

        $this->assertSame([
            "header"      => "Episode 1",
            "headerLines" => ["Kind: captions", "Language: en"],
            "regions"     => [[
                "id"             => "fred",
                "width"          => "40%",
                "lines"          => "3",
                "regionanchor"   => "0%,100%",
                "viewportanchor" => "10%,90%",
                "scroll"         => "up",
            ]],
        ], $subtitle->getFormatData("vtt"));
        $this->assertSame([["text" => "Translated by Jane Doe", "beforeCueIndex" => 0]], $subtitle->getComments());
        $this->assertSame("intro", $cue->getIdentifier());
        $this->assertSame(["region" => "fred", "align" => "left", "line" => "85%"], $cue->getFormatData("vtt"));
        $this->assertNull($cue->getAlignment());
        $this->assertSame("<v Fred>Hi, I am Fred &amp; this is <c.yellow>Bob</c></v>", $cue->getText());
    }


    public function testCommentsKeepTheirPositionBetweenCues(): void
    {
        $raw = "WEBVTT\n\nNOTE\nfirst line\nsecond line\n\n00:01.000 --> 00:02.000\nOne\n\n"
               . "NOTE\tbetween\n\n00:03.000 --> 00:04.000\nTwo\n\nNOTE end of file\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);

        $this->assertSame([
            ["text" => "first line\nsecond line", "beforeCueIndex" => 0],
            ["text" => "between", "beforeCueIndex" => 1],
            ["text" => "end of file", "beforeCueIndex" => 2],
        ], $subtitle->getComments());
    }


    public function testIdentifierThatStartsWithNoteIsACueIdentifier(): void
    {
        $raw = "WEBVTT\n\nNOTE-1\n00:01.000 --> 00:02.000\nOne\n\nNotes1\n00:03.000 --> 00:04.000\nTwo\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);

        $this->assertSame([], $subtitle->getComments());
        $this->assertSame("NOTE-1", $subtitle->getCues()[0]->getIdentifier());
        $this->assertSame("Notes1", $subtitle->getCues()[1]->getIdentifier());
    }


    public function testCueWithoutIdentifierHasNullIdentifier(): void
    {
        $subtitle = Subtitle::parse("WEBVTT\n\n00:01.000 --> 00:02.000\nOne\n", WebVttParser::class);

        $this->assertNull($subtitle->getCues()[0]->getIdentifier());
        $this->assertSame([], $subtitle->getFormatData("vtt"));
    }


    public function testStylesKeepTheExactCss(): void
    {
        $raw = "WEBVTT\n\nSTYLE\n::cue {\n\tcolor: lime;\n}\n\n00:01.000 --> 00:02.000\nOne\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);

        $this->assertSame(["styles" => ["::cue {\n\tcolor: lime;\n}"]], $subtitle->getFormatData("vtt"));
    }


    public function testStyleAndRegionBlocksAfterTheFirstCueAreIgnored(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000\nOne\n\nSTYLE\n::cue { color: lime }\n\nREGION\nid:late\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);

        $this->assertSame(1, count($subtitle->getCues()));
        $this->assertSame([], $subtitle->getFormatData("vtt"));
    }


    public function testStyleKeywordWithTextIsNoStyleBlock(): void
    {
        $raw = "WEBVTT\n\nSTYLES\n::cue {}\n\n00:01.000 --> 00:02.000\nOne\n";

        $this->assertSame([], Subtitle::parse($raw, WebVttParser::class)->getFormatData("vtt"));
    }


    public function testRegionSettingsWithoutValueAreIgnored(): void
    {
        $raw = "WEBVTT\n\nREGION\nid:fred width: :3 scroll:up foo:bar\n\n00:01.000 --> 00:02.000\nOne\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);

        $this->assertSame(["regions" => [["id" => "fred", "scroll" => "up"]]], $subtitle->getFormatData("vtt"));
    }


    public function testTimestampWithoutDotThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("time-string of at least one cue could not be parsed: 00:00:01x000");
        Subtitle::parse("WEBVTT\n\n00:00:01x000 --> 00:00:04.000\nText\n", WebVttParser::class);
    }


    public function testTextBetweenStartTimeAndArrowThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("time-string of at least one cue could not be parsed");
        Subtitle::parse("WEBVTT\n\n00:00:01.000 align:left --> 00:00:04.000\nText\n", WebVttParser::class);
    }


    public function testArrowWithoutSpacesParses(): void
    {
        $subtitle = Subtitle::parse("WEBVTT\n\n00:01.000-->00:02.500\nText\n", WebVttParser::class);

        $this->assertSame(2.5, $subtitle->getCues()[0]->getEnd());
    }


    public function testTimingLineWithoutEmptyLineStartsANewCue(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000\nOne\n00:03.000 --> 00:04.000\nTwo\n";

        $subtitle = Subtitle::parse($raw, WebVttParser::class);

        $this->assertSame(["One", "Two"], array_map(fn (SubtitleCue $cue): string => $cue->getText(), $subtitle->getCues()));
    }


    public function testCueSettingsKeepTheExactValues(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000 \tvertical:rl  size:50.5%  foo:bar position:10%,line-left noColon\nOne\n";

        $cue = Subtitle::parse($raw, WebVttParser::class)->getCues()[0];

        $this->assertSame(["vertical" => "rl", "size" => "50.5%", "position" => "10%,line-left"], $cue->getFormatData("vtt"));
        $this->assertNull($cue->getAlignment());
    }


    #[DataProvider("alignmentProvider")]
    public function testCueSettingsMapToAlignment(string $settings, ?int $alignment): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000 $settings\nOne\n";

        $this->assertSame($alignment, Subtitle::parse($raw, WebVttParser::class)->getCues()[0]->getAlignment());
    }


    public static function alignmentProvider(): array
    {
        return [
            "no settings"              => ["", null],
            "line 0"                   => ["line:0", 8],
            "line 0 percent left"      => ["line:0% align:left", 7],
            "line 0 right"             => ["align:right line:0", 9],
            "middle"                   => ["line:50%,center", 5],
            "middle left"              => ["line:50%,center align:left", 4],
            "bottom line"              => ["line:-1", 2],
            "bottom right"             => ["line:100%,end align:right", 3],
            "align left"               => ["align:left", 1],
            "align center"             => ["align:center", 2],
            "align start is unclear"   => ["align:start", null],
            "line 85 percent unclear"  => ["line:85%", null],
            "vertical text is unclear" => ["vertical:rl line:0", null],
            "only position"            => ["position:10%", null],
        ];
    }


    public function testEntitiesForSpacesAndDirectionMarksAreDecoded(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:02.000\nA&nbsp;B &lrm;C&rlm; &amp; &lt;D&gt;\n";

        $this->assertSame(
            "A\u{00A0}B \u{200E}C\u{200F} &amp; &lt;D&gt;",
            Subtitle::parse($raw, WebVttParser::class)->getCues()[0]->getText()
        );
    }


    public function testInlineTimestampsAreKept(): void
    {
        $raw = "WEBVTT\n\n00:01.000 --> 00:04.000\nOne <00:00:02.500>two <00:03.000>three\n";

        $this->assertSame(
            "One <00:00:02.500>two <00:03.000>three",
            Subtitle::parse($raw, WebVttParser::class)->getCues()[0]->getText()
        );
    }


    public function testHeaderLineWithArrowThrowsException(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage("No empty line found after the first line containing WEBVTT");
        Subtitle::parse("WEBVTT\nKind: captions\n00:01.000 --> 00:02.000\nText\n", WebVttParser::class);
    }


    private function validWithHeaderText(): string
    {
        return str_replace("WEBVTT\n", "WEBVTT - some title\n", file_get_contents(__DIR__ . "/../files/vtt/valid.vtt"));
    }


    public function testWebVttWithCrCrLfLineEndingsKeepsCuesApart(): void
    {
        $raw = "WEBVTT\r\r\n\r\r\n00:01.000 --> 00:02.000\r\r\nFirst\r\r\n\r\r\n00:03.000 --> 00:04.000\r\r\nSecond\r\r\n";

        $cues = Subtitle::parse($raw, WebVttParser::class)->getCues();

        $this->assertSame(2, count($cues));
        $this->assertSame("Second", $cues[1]->getText());
    }
}
