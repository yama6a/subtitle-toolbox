<?php

namespace SubtitleToolbox;

use InvalidArgumentException;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Formatters\WebVttFormatter;
use SubtitleToolbox\Parsers\SubRipParser;
use SubtitleToolbox\Parsers\WebVttParser;

class HearingImpairedRemovalTest extends \PHPUnit\Framework\TestCase
{
    private const FILES = __DIR__ . "/files/hearing-impaired/";


    private function makeSubtitle(string ...$texts): Subtitle
    {
        $subtitle = new Subtitle();
        foreach ($texts as $index => $text) {
            $subtitle->addCue(new SubtitleCue($index, $index + 1, $text));
        }

        return $subtitle;
    }


    private function getTexts(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): string => $cue->getText(), array_values($subtitle->getCues()));
    }


    private function remove(string $text, ?HearingImpairedOptions $options = null): array
    {
        return $this->getTexts($this->makeSubtitle($text)->removeHearingImpaired($options));
    }


    public function testRealSubRipFileParsesAndRoundTrips(): void
    {
        $content  = file_get_contents(self::FILES . "own_sdh.srt");
        $subtitle = Subtitle::parse($content, SubRipParser::class);
        $cues     = array_values($subtitle->getCues());

        $this->assertCount(14, $cues);
        $this->assertSame([1.0, 3.0, "[TRAIN WHISTLE BLOWS]"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([39.5, 42.0, "- # The wheels go round #\n- It stopped raining. (birds chirping)"],
                          [$cues[13]->getStart(), $cues[13]->getEnd(), $cues[13]->getText()]);
        $this->assertSame($content, $subtitle->format(SubRipFormatter::class, ["lineEnding" => "\r\n", "bom" => true]));
    }


    public function testRealSubRipFileWithDefaultOptions(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::FILES . "own_sdh.srt"), SubRipParser::class);

        $this->assertSame($subtitle, $subtitle->removeHearingImpaired());
        $this->assertSame(file_get_contents(self::FILES . "own_sdh_removed.srt"),
                          $subtitle->format(SubRipFormatter::class, ["lineEnding" => "\r\n", "bom" => true]));
        $this->assertSame([], $subtitle->getErrors());
    }


    public function testRealSubRipFileWithAllOptions(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::FILES . "own_sdh.srt"), SubRipParser::class)
            ->removeHearingImpaired(new HearingImpairedOptions(
                speakerLabelsUpperCaseOnly: false,
                customBrackets: [["{", "}"]],
                lyrics: true,
            ));

        $this->assertSame(file_get_contents(self::FILES . "own_sdh_removed_all_options.srt"),
                          $subtitle->format(SubRipFormatter::class));
    }


    public function testRealWebVttFileParsesAndRoundTrips(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::FILES . "own_sdh.vtt"), WebVttParser::class);
        $cues     = array_values($subtitle->getCues());

        $this->assertCount(8, $cues);
        $this->assertSame([1.0, 3.0, "[wind howling]"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([21.5, 24.0, "<i>[door opens]\n(footsteps)</i>"],
                          [$cues[7]->getStart(), $cues[7]->getEnd(), $cues[7]->getText()]);

        $again = Subtitle::parse($subtitle->format(WebVttFormatter::class), WebVttParser::class);
        $this->assertSame($this->getTexts($subtitle), $this->getTexts($again));
        $this->assertSame($subtitle->getComments(), $again->getComments());
    }


    public function testRealWebVttFileWithDefaultOptions(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::FILES . "own_sdh.vtt"), WebVttParser::class)
            ->removeHearingImpaired();

        $this->assertSame(file_get_contents(self::FILES . "own_sdh_removed.vtt"), $subtitle->format(WebVttFormatter::class));
        $this->assertSame([], $subtitle->getErrors());
    }


    public function testExamplesOfTheIssue(): void
    {
        $this->assertSame([], $this->remove("[DOOR SLAMS]"));
        $this->assertSame(["You came back."], $this->remove("(laughs) You came back."));
        $this->assertSame(["Where were you?"], $this->remove("JOHN: Where were you?"));
        $this->assertSame(["- No!\n- Yes."], $this->remove("- [gasps] No!\n- MARY: Yes."));
        $this->assertSame(["# Never gonna stop #"], $this->remove("# Never gonna stop #"));
        $this->assertSame([], $this->remove("# Never gonna stop #", new HearingImpairedOptions(lyrics: true)));
        $this->assertSame(["<i>Over here.</i>"], $this->remove("<i>(whispering) Over here.</i>"));
    }


    public function testRemovesSpacesAroundAnAnnotation(): void
    {
        $this->assertSame(["Wait for it."], $this->remove("Wait (sighs) for it."));
        $this->assertSame(["Wait for it."], $this->remove("Wait for it (sighs)."));
        $this->assertSame(["Wait."], $this->remove("Wait. [door closes]"));
        $this->assertSame(["- No!"], $this->remove("-[gasps] No!"));
    }


    public function testBracketsAcrossTagsAndLines(): void
    {
        $this->assertSame(["Hello."], $this->remove("<font color=\"#ff0000\">[BELL]</font> Hello."));
        $this->assertSame(["Hello."], $this->remove("[BELL <i>RINGS</i>] Hello."));
        $this->assertSame(["Hi."], $this->remove("(woman\nspeaking) Hi."));
        $this->assertSame(["Hi."], $this->remove("(MARY): Hi."));
        $this->assertSame(["Hi."], $this->remove("[[echo]] Hi."));
        $this->assertSame(["Hi (there"], $this->remove("Hi (there"));
    }


    public function testKeepsTagsAndEntities(): void
    {
        $this->assertSame(["<i>Hello</i>"], $this->remove("<i>[sighs]\nHello</i>"));
        $this->assertSame(["<i>Hello</i>"], $this->remove("<i>Hello\n[sighs]</i>"));
        $this->assertSame(["<v Anna>Good morning."], $this->remove("<v Anna>(coughs) Good morning."));
        $this->assertSame(["Fish &amp; &lt;3"], $this->remove("Fish &amp; (chips) &lt;3"));
        $this->assertSame(["Hi &amp; bye"], $this->remove("Hi &amp; bye"));
        $this->assertSame(["- Hi.\n- Bye."], $this->remove("- Hi.\n- (laughs) Bye."));
    }


    public function testSpeakerLabels(): void
    {
        $this->assertSame(["Run!"], $this->remove("MAN 2: Run!"));
        $this->assertSame(["Sit."], $this->remove("DR. O'NEIL: Sit."));
        $this->assertSame(["Hi."], $this->remove("ÉMILE: Hi."));
        $this->assertSame(["Note: this stays."], $this->remove("Note: this stays."));
        $this->assertSame(["THE 8:15 TRAIN"], $this->remove("THE 8:15 TRAIN"));
        $this->assertSame(["Where?"], $this->remove("JOHN:\nWhere?"));
        $this->assertSame(["Hi."], $this->remove("Baker: Hi.", new HearingImpairedOptions(speakerLabelsUpperCaseOnly: false)));
        $this->assertSame(["JOHN: Hi."], $this->remove("JOHN: Hi.", new HearingImpairedOptions(speakerLabels: false)));
    }


    public function testMusicLines(): void
    {
        $this->assertSame([], $this->remove("♪ ♪"));
        $this->assertSame([], $this->remove("- #"));
        $this->assertSame(["Hi."], $this->remove("♫\nHi."));
        $this->assertSame(["♪ la la ♪", "#1 fan"], $this->getTexts($this->makeSubtitle("♪ la la ♪", "#1 fan")
            ->removeHearingImpaired()));
        $this->assertSame(["#1 fan"], $this->getTexts($this->makeSubtitle("♪ la la ♪", "#1 fan")
            ->removeHearingImpaired(new HearingImpairedOptions(lyrics: true))));
        $this->assertSame(["Hi."], $this->remove("♪ The rain\nkeeps falling ♪\nHi.", new HearingImpairedOptions(lyrics: true)));
        $this->assertSame(["♪ ♪"], $this->remove("♪ ♪", new HearingImpairedOptions(musicOnlyLines: false)));
    }


    public function testDialogueDashes(): void
    {
        $this->assertSame(["Is it open?"], $this->remove("- Is it open?\n- (laughs)"));
        $this->assertSame(["Yes?"], $this->remove("- JOHN:\n- Yes?"));
        $this->assertSame(["- A.\n- B."], $this->remove("- A.\n- [sighs]\n- B."));
        $this->assertSame(["- Is it open?"], $this->remove("- Is it open? (laughs)"));
    }


    public function testCustomBrackets(): void
    {
        $this->assertSame(["Hi {laughs}"], $this->remove("Hi {laughs}"));
        $this->assertSame(["{\\an8}Hi"], $this->remove("{\\an8}Hi {laughs}", new HearingImpairedOptions(customBrackets: [["{", "}"]])));
        $this->assertSame(["Hi"], $this->remove("Hi *sighs*", new HearingImpairedOptions(customBrackets: [["*", "*"]])));
        $this->assertSame(["[A] (B)"], $this->remove("[A] (B)", new HearingImpairedOptions(squareBrackets: false, parentheses: false)));
    }


    public function testRejectsInvalidCustomBrackets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HearingImpairedOptions(customBrackets: [["{", ""]]);
    }


    public function testRemovesEmptyCuesAndKeepsComments(): void
    {
        $subtitle = $this->makeSubtitle("first", "<i>[MUSIC]</i>", "[DOOR]\n(SIGHS)", "last");
        $subtitle->addCue(new SubtitleCue(10, 11, ""));
        $subtitle->addComment("before music", 1)->addComment("before door", 2)->addComment("before last", 3);

        $subtitle->removeHearingImpaired();

        $this->assertSame(["first", "last", ""], $this->getTexts($subtitle));
        $this->assertSame([
            ["text" => "before music", "beforeCueIndex" => 1],
            ["text" => "before door", "beforeCueIndex" => 1],
            ["text" => "before last", "beforeCueIndex" => 1],
        ], $subtitle->getComments());
        $this->assertSame([], $subtitle->getErrors());
    }


    public function testKeepsInvalidUtf8(): void
    {
        $this->assertSame(["\xE9t\xE9 [x]"], $this->remove("\xE9t\xE9 [x]"));
    }


    public function testIsHearingImpaired(): void
    {
        $options = new HearingImpairedOptions();

        $this->assertTrue($options->isHearingImpaired("[DOOR SLAMS]"));
        $this->assertTrue($options->isHearingImpaired("JOHN: Hi."));
        $this->assertTrue($options->isHearingImpaired("♪ ♪"));
        $this->assertFalse($options->isHearingImpaired("Note: this stays."));
        $this->assertFalse($options->isHearingImpaired("♪ la la ♪"));
        $this->assertTrue((new HearingImpairedOptions(lyrics: true))->isHearingImpaired("♪ la la ♪"));
    }
}
