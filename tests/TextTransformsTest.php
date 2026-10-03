<?php

namespace SubtitleToolbox;

use InvalidArgumentException;
use SubtitleToolbox\Validation\ValidationRules;

class TextTransformsTest extends \PHPUnit\Framework\TestCase
{
    private const FILES = __DIR__ . "/files/transforms/";


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


    private function parseCaptions(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . "own_cea608_caps.vtt"), Format::WebVtt);
    }


    private function parseMultilingual(): Subtitle
    {
        return Subtitle::fromString(file_get_contents(self::FILES . "own_multilingual_caps.srt"), Format::SubRip);
    }


    public function testRealCaptionFileParsesAndRoundTrips(): void
    {
        $subtitle = $this->parseCaptions();
        $cues     = $subtitle->getCues();

        $this->assertCount(5, $cues);
        $this->assertSame([1.001, 3.336, ">> GOOD MORNING. THE 8:15 TRAIN\nTO MAIN STREET IS LATE."],
                          [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([10.177, 12.846, "THE BAKERY CAFÉ ON PLATFORM 2\nIS OPEN. ÄPFEL, STRASSE?"],
                          [$cues[4]->getStart(), $cues[4]->getEnd(), $cues[4]->getText()]);

        $again = Subtitle::fromString($subtitle->toString(Format::WebVtt), Format::WebVtt);
        $this->assertSame($this->getTexts($subtitle), $this->getTexts($again));
        $this->assertSame($subtitle->getComments(), $again->getComments());
    }


    public function testRealCaptionFileInSentenceCase(): void
    {
        $subtitle = $this->parseCaptions();

        $this->assertSame($subtitle, $subtitle->changeCase("sentence"));
        $this->assertSame(file_get_contents(self::FILES . "own_cea608_caps_sentence.vtt"),
                          $subtitle->toString(Format::WebVtt));
    }


    public function testRealCaptionFileCleanedUp(): void
    {
        $subtitle = $this->parseCaptions()
            ->replaceText('/\[[^\]]*\]/', "", true)
            ->replaceText('/\.{4,}/', "...", true)
            ->stripFormatting();

        $this->assertSame(file_get_contents(self::FILES . "own_cea608_caps_cleaned.vtt"),
                          $subtitle->toString(Format::WebVtt));
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testRealMultilingualFileParsesAndRoundTrips(): void
    {
        $subtitle = $this->parseMultilingual();
        $cues     = $subtitle->getCues();

        $this->assertCount(4, $cues);
        $this->assertSame([1.0, 3.5, "<b>ΟΔΟΣ ΣΤΑΘΜΟΥ 4</b>"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([8.5, 10.0, "<i>RAIN &lt;3 &amp; SNOW</i>"], [$cues[3]->getStart(), $cues[3]->getEnd(), $cues[3]->getText()]);

        $again = Subtitle::fromString($subtitle->toString(Format::SubRip), Format::SubRip);
        $this->assertSame($this->getTexts($subtitle), $this->getTexts($again));
    }


    public function testRealMultilingualFileInLowerCase(): void
    {
        $this->assertSame([
            "<b>οδος σταθμου 4</b>",
            "große bäckerei. öffnet um 6 uhr!",
            "<font color=\"#ffff00\">i\u{307}stasyon kapisi işikli.</font>",
            "<i>rain &lt;3 &amp; snow</i>",
        ], $this->getTexts($this->parseMultilingual()->changeCase("lower")));
    }


    public function testTurkishRulesApplyToEveryCue(): void
    {
        $this->assertSame([
            "<b>Οδος σταθμου 4</b>",
            "Große bäckereı. Öffnet um 6 uhr!",
            "<font color=\"#ffff00\">İstasyon kapısı ışıklı.</font>",
            "<i>Raın &lt;3 &amp; snow</i>",
        ], $this->getTexts($this->parseMultilingual()->changeCase("sentence", "tr-TR")));
    }


    public function testUpperCaseKeepsTagsAndEntities(): void
    {
        $subtitle = $this->makeSubtitle("<i>stop</i>", "tom &amp; <font color=\"#ff0000\">jerry</font> &lt;3");

        $this->assertSame(["<i>STOP</i>", "TOM &amp; <font color=\"#ff0000\">JERRY</font> &lt;3"],
                          $this->getTexts($subtitle->changeCase("upper")));
    }


    public function testUpperCaseOfGermanAndTurkish(): void
    {
        $this->assertSame(["STRASSE", "ISTANBUL"], $this->getTexts($this->makeSubtitle("straße", "istanbul")->changeCase("upper")));
        $this->assertSame(["İSTANBUL KAPI"], $this->getTexts($this->makeSubtitle("istanbul kapı")->changeCase("upper", "tr")));
        $this->assertSame(["istanbul kapı"], $this->getTexts($this->makeSubtitle("İSTANBUL KAPI")->changeCase("lower", "az")));
    }


    public function testLowerCaseUsesGreekFinalSigma(): void
    {
        $this->assertSame(["οδος σας, σ"], $this->getTexts($this->makeSubtitle("ΟΔΟΣ ΣΑΣ, Σ")->changeCase("lower")));
    }


    public function testCaseChangeKeepsBytesOfInvalidUtf8(): void
    {
        $this->assertSame(["CAF\xe9 <i>NO\xebL</i>"], $this->getTexts($this->makeSubtitle("caf\xe9 <i>no\xebl</i>")->changeCase("upper")));
    }


    public function testSentenceCase(): void
    {
        $subtitle = $this->makeSubtitle(
            "WHERE ARE YOU GOING? HOME.",
            "<i>WAIT...</i> <b>WHAT?!</b> \"NO.\" OK",
            "- READ WWW.EXAMPLE.COM.\n- 3.5 KM, THEN STOP!",
            "STRASSE. ßAD",
        );

        $this->assertSame([
            "Where are you going? Home.",
            "<i>Wait...</i> <b>What?!</b> \"No.\" Ok",
            "- Read www.example.com.\n- 3.5 km, then stop!",
            "Strasse. Ssad",
        ], $this->getTexts($subtitle->changeCase("sentence")));
    }


    public function testChangeCaseRejectsUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeSubtitle("text")->changeCase("title");
    }


    public function testReplaceTextMatchesVisibleTextOnly(): void
    {
        $subtitle = $this->makeSubtitle("<i>Colour</i> me surprised", "Tom &amp; Jerry", "<font color=\"#ff0000\">red</font> amp");

        $subtitle->replaceText("Colour", "Color")->replaceText("&", "and")->replaceText("amp", "lamp")->replaceText("ff", "XX");

        $this->assertSame(["<i>Color</i> me surprised", "Tom and Jerry", "<font color=\"#ff0000\">red</font> lamp"],
                          $this->getTexts($subtitle));
    }


    public function testReplaceTextEscapesTheReplacement(): void
    {
        $subtitle = $this->makeSubtitle("I love it", "<b>x</b>");

        $subtitle->replaceText("love", "<3 & more")->replaceText("x", "<b>");

        $this->assertSame(["I &lt;3 &amp; more it", "<b>&lt;b&gt;</b>"], $this->getTexts($subtitle));
    }


    public function testReplaceTextKeepsUnescapedWebVttCharacters(): void
    {
        $subtitle = $this->makeSubtitle(">> TOM & JERRY", ">> A &amp; B");

        $subtitle->replaceText("TOM", "Tom &lt;")->replaceText("A", "a");

        $this->assertSame([">> Tom &amp;lt; & JERRY", ">> a &amp; B"], $this->getTexts($subtitle));
    }


    public function testReplaceTextWithRegex(): void
    {
        $subtitle = $this->makeSubtitle("Wait.....", "<i>colour</i> and COLOUR");

        $subtitle->replaceText('/\.{4,}/', "...", true)->replaceText('/col(ou)r/', 'col$1r!', true, false);

        $this->assertSame(["Wait...", "<i>colour!</i> and colOUr!"], $this->getTexts($subtitle));
    }


    public function testReplaceTextCaseInsensitiveWithoutRegex(): void
    {
        $subtitle = $this->makeSubtitle("Ärger and ärger", "Price: \$1");

        $subtitle->replaceText("ÄRGER", "joy", false, false)->replaceText("price", '$1 \1', false, false);

        $this->assertSame(["joy and joy", '$1 \1: $1'], $this->getTexts($subtitle));
    }


    public function testReplaceTextRejectsInvalidInput(): void
    {
        $subtitle = $this->makeSubtitle("text");

        try {
            $subtitle->replaceText("", "x");
            $this->fail("An empty search must throw.");
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $subtitle->replaceText("/(/", "x", true);
    }


    public function testStripFormatting(): void
    {
        $texts = ["<b>Run</b>, <font color=\"#ff0000\">now</font>!", "<b><i>Run</i></b> &amp; <v Fred>hide"];

        $this->assertSame(["Run, now!", "Run &amp; hide"], $this->getTexts($this->makeSubtitle(...$texts)->stripFormatting()));
        $this->assertSame(["Run, now!", "<i>Run</i> &amp; hide"],
                          $this->getTexts($this->makeSubtitle(...$texts)->stripFormatting(["i"])));
    }


    public function testStripFormattingKeepsWordTimestamps(): void
    {
        $line = "<b>One</b> <00:00:01.500>two <00:00:02.000><i>three</i>";

        $this->assertSame(["One <00:00:01.500>two <00:00:02.000>three"],
                          $this->getTexts($this->makeSubtitle($line)->stripFormatting()));
        $this->assertSame(["One two <i>three</i>"], $this->getTexts($this->makeSubtitle($line)->stripFormatting(["i"], false)));
    }


    public function testMapTextGetsDecodedTextRunsAndTheCue(): void
    {
        $subtitle = $this->makeSubtitle("<i>a &amp; b</i> c", "&lt;d&gt;");
        $calls    = [];

        $subtitle->mapText(function (string $text, SubtitleCue $cue) use (&$calls): string {
            $calls[] = [$text, $cue->getStart()];

            return "[$text]";
        });

        $this->assertSame([["a & b", 0.0], [" c", 0.0], ["<d>", 1.0]], $calls);
        $this->assertSame(["<i>[a &amp; b]</i>[ c]", "[&lt;d&gt;]"], $this->getTexts($subtitle));
    }


    public function testMapTextKeepsUnchangedRunsByteForByte(): void
    {
        $subtitle = $this->makeSubtitle("a&nbsp;b <i>&#39;c&#39;</i>");

        $subtitle->mapText(fn (string $text): string => $text);

        $this->assertSame(["a&nbsp;b <i>&#39;c&#39;</i>"], $this->getTexts($subtitle));
    }


    public function testMapLinesGetsFullLines(): void
    {
        $subtitle = $this->makeSubtitle("one\n<i>two</i>");

        $subtitle->mapLines(fn (string $line, SubtitleCue $cue): string => "<font color=\"#ffff00\">$line</font>");

        $this->assertSame(["<font color=\"#ffff00\">one</font>\n<font color=\"#ffff00\"><i>two</i></font>"],
                          $this->getTexts($subtitle));
    }


    public function testRemovesCuesThatBecomeEmptyAndKeepsComments(): void
    {
        $subtitle = $this->makeSubtitle("first", "<i>[MUSIC]</i>", "[DOOR]\n[MUSIC]", "last");
        $subtitle->addCue(new SubtitleCue(10, 11, ""));
        $subtitle->addComment("before music", 1)->addComment("before door", 2)->addComment("before last", 3);

        $subtitle->replaceText('/\[\w+\]/', "", true);

        $this->assertSame(["first", "last", ""], $this->getTexts($subtitle));
        $this->assertSame([
            ["text" => "before music", "beforeCueIndex" => 1],
            ["text" => "before door", "beforeCueIndex" => 1],
            ["text" => "before last", "beforeCueIndex" => 1],
        ], $subtitle->getComments());
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }
}
