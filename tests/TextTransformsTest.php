<?php

declare(strict_types=1);

namespace SubtitleToolbox;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use SubtitleToolbox\Tests\Support\TestSubtitles;
use SubtitleToolbox\Validation\ValidationRules;

class TextTransformsTest extends \PHPUnit\Framework\TestCase
{
    private const FILES = __DIR__ . "/files/transforms/";


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
        $this->assertSame(TestSubtitles::texts($subtitle), TestSubtitles::texts($again));
        $this->assertEquals($subtitle->getComments(), $again->getComments());
    }


    public function testRealCaptionFileInSentenceCase(): void
    {
        $subtitle = $this->parseCaptions();

        $this->assertSame($subtitle, $subtitle->changeCase(CaseMode::Sentence));
        $this->assertSame(file_get_contents(self::FILES . "own_cea608_caps_sentence.vtt"),
                          $subtitle->toString(Format::WebVtt));
    }


    public function testRealRunOnCaptionsContinueSentencesAcrossCues(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "own_cea608_run_on.vtt"), Format::WebVtt);

        $this->assertSame(file_get_contents(self::FILES . "own_cea608_run_on_sentence.vtt"),
                          $subtitle->changeCase(CaseMode::Sentence)->toString(Format::WebVtt));
    }


    public function testRealSoundCuesAndSpeakerLabelsStartSentences(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::FILES . "own_cea608_sound_cues.vtt"), Format::WebVtt);

        $this->assertSame(file_get_contents(self::FILES . "own_cea608_sound_cues_sentence.vtt"),
                          $subtitle->changeCase(CaseMode::Sentence)->toString(Format::WebVtt));
    }


    public function testRealCaptionFileCleanedUp(): void
    {
        $subtitle = $this->parseCaptions()
            ->replaceText('/\[[^\]]*\]/', "", new ReplaceTextOptions(regex: true))
            ->replaceText('/\.{4,}/', "...", new ReplaceTextOptions(regex: true))
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
        $this->assertSame(TestSubtitles::texts($subtitle), TestSubtitles::texts($again));
    }


    public function testRealMultilingualFileInLowerCase(): void
    {
        $this->assertSame([
            "<b>οδος σταθμου 4</b>",
            "große bäckerei. öffnet um 6 uhr!",
            "<font color=\"#ffff00\">i\u{307}stasyon kapisi işikli.</font>",
            "<i>rain &lt;3 &amp; snow</i>",
        ], TestSubtitles::texts($this->parseMultilingual()->changeCase(CaseMode::Lower)));
    }


    public function testTurkishRulesApplyToEveryCue(): void
    {
        $this->assertSame([
            "<b>Οδος σταθμου 4</b>",
            "große bäckereı. Öffnet um 6 uhr!",
            "<font color=\"#ffff00\">İstasyon kapısı ışıklı.</font>",
            "<i>Raın &lt;3 &amp; snow</i>",
        ], TestSubtitles::texts($this->parseMultilingual()->changeCase(CaseMode::Sentence, "tr-TR")));
    }


    public function testUpperCaseKeepsTagsAndEntities(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<i>stop</i>", "tom &amp; <font color=\"#ff0000\">jerry</font> &lt;3"]);

        $this->assertSame(["<i>STOP</i>", "TOM &amp; <font color=\"#ff0000\">JERRY</font> &lt;3"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Upper)));
    }


    public function testUpperCaseOfGermanAndTurkish(): void
    {
        $this->assertSame(["STRASSE", "ISTANBUL"], TestSubtitles::texts(TestSubtitles::fromTexts(["straße", "istanbul"])->changeCase(CaseMode::Upper)));
        $this->assertSame(["İSTANBUL KAPI"], TestSubtitles::texts(TestSubtitles::fromTexts(["istanbul kapı"])->changeCase(CaseMode::Upper, "tr")));
        $this->assertSame(["istanbul kapı"], TestSubtitles::texts(TestSubtitles::fromTexts(["İSTANBUL KAPI"])->changeCase(CaseMode::Lower, "az")));
    }


    public function testLowerCaseUsesGreekFinalSigma(): void
    {
        $this->assertSame(["οδος σας, σ"], TestSubtitles::texts(TestSubtitles::fromTexts(["ΟΔΟΣ ΣΑΣ, Σ"])->changeCase(CaseMode::Lower)));
    }


    public function testCaseChangeKeepsBytesOfInvalidUtf8(): void
    {
        $this->assertSame(["CAF\xe9 <i>NO\xebL</i>"], TestSubtitles::texts(TestSubtitles::fromTexts(["caf\xe9 <i>no\xebl</i>"])->changeCase(CaseMode::Upper)));
    }


    public function testSentenceCase(): void
    {
        $subtitle = TestSubtitles::fromTexts([
            "WHERE ARE YOU GOING? HOME.",
            "<i>WAIT...</i> <b>WHAT?!</b> \"NO.\" OK.",
            "- READ WWW.EXAMPLE.COM.\n- 3.5 KM, THEN STOP!",
            "STRASSE. ßAD",
        ]);

        $this->assertSame([
            "Where are you going? Home.",
            "<i>Wait...</i> <b>What?!</b> \"No.\" Ok.",
            "- Read www.example.com.\n- 3.5 km, then stop!",
            "Strasse. Ssad",
        ], TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    public function testSentenceContinuesInTheNextCue(): void
    {
        $subtitle = TestSubtitles::fromTexts(["WE WENT TO THE", "STORE ON MONDAY, AND I THINK I SAW JOHN.", "THEN \"BYE.\"", "<i>SO…</i>", "OK"]);

        $this->assertSame(["We went to the", "store on monday, and I think I saw john.", "Then \"bye.\"", "<i>So…</i>", "Ok"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    public function testSpeakerChangeAndDialogueDashStartASentence(): void
    {
        $subtitle = TestSubtitles::fromTexts(["WAIT FOR", ">> ME AT THE", ">>>STATION", "AND\n- WHY\n\u{2013} FOR", "<i>- LUNCH</i>", "AT", "-20 DEGREES"]);

        $this->assertSame(["Wait for", ">> Me at the", ">>>Station", "and\n- Why\n\u{2013} For", "<i>- Lunch</i>", "at", "-20 degrees"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    public function testAnnotationCuesStartASentenceInInvalidUtf8(): void
    {
        $subtitle = TestSubtitles::fromTexts(["CAF\xe9 [BELL]", "WAIT \xe9", "NO\xeb JOHN:", "GO \xe9", "(LAUGHS) \xe9", "\u{266A} LA \xe9 \u{266A}", "OK"]);

        $this->assertSame(["Caf\xe9 [bell]", "Wait \xe9", "no\xeb john:", "Go \xe9", "(Laughs) \xe9", "\u{266A} La \xe9 \u{266A}", "Ok"],
                          TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    #[DataProvider("sentenceGapProvider")]
    public function testGapStartsASentence(float $start, string $expected): void
    {
        $subtitle = (new Subtitle())->addCues([new SubtitleCue(0.0, 1.0, "WAIT FOR"), new SubtitleCue($start, $start + 1, "ME")]);

        $this->assertSame(["Wait for", $expected], TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence)));
    }


    /**
     * @return array<string, array{float, string}>
     */
    public static function sentenceGapProvider(): array
    {
        return [
            "1.999 s" => [2.999, "me"],
            "2 s"     => [3.0, "Me"],
            "5 s"     => [6.0, "Me"],
        ];
    }


    #[DataProvider("englishIProvider")]
    public function testEnglishPronounIInUpperCase(?string $language, string $expected): void
    {
        $subtitle = TestSubtitles::fromTexts(["WELL, I'M HERE AND I'LL STAY. I\u{2019}VE, I'D, I-I, HI, PI, 3I, I'S, I.E."]);

        $this->assertSame([$expected], TestSubtitles::texts($subtitle->changeCase(CaseMode::Sentence, $language)));
    }


    /**
     * @return array<string, array{?string, string}>
     */
    public static function englishIProvider(): array
    {
        $english = "Well, I'm here and I'll stay. I\u{2019}ve, I'd, I-I, hi, pi, 3i, i's, i.e.";

        return [
            "no language" => [null, $english],
            "en"          => ["en", $english],
            "en-GB"       => ["en-GB", $english],
            "de"          => ["de", "Well, i'm here and i'll stay. I\u{2019}ve, i'd, i-i, hi, pi, 3i, i's, i.e."],
        ];
    }


    public function testGermanKeepsALoneLowerCaseI(): void
    {
        $this->assertSame(["Ich bin da, i."], TestSubtitles::texts(TestSubtitles::fromTexts(["ICH BIN DA, I."])->changeCase(CaseMode::Sentence, "de")));
    }


    public function testLowerCaseKeepsTheEnglishPronounI(): void
    {
        $this->assertSame(["i think"], TestSubtitles::texts(TestSubtitles::fromTexts(["I THINK"])->changeCase(CaseMode::Lower)));
    }


    public function testReplaceTextMatchesVisibleTextOnly(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<i>Colour</i> me surprised", "Tom &amp; Jerry", "<font color=\"#ff0000\">red</font> amp"]);

        $subtitle->replaceText("Colour", "Color")->replaceText("&", "and")->replaceText("amp", "lamp")->replaceText("ff", "XX");

        $this->assertSame(["<i>Color</i> me surprised", "Tom and Jerry", "<font color=\"#ff0000\">red</font> lamp"],
                          TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextEscapesTheReplacement(): void
    {
        $subtitle = TestSubtitles::fromTexts(["I love it", "<b>x</b>"]);

        $subtitle->replaceText("love", "<3 & more")->replaceText("x", "<b>");

        $this->assertSame(["I &lt;3 &amp; more it", "<b>&lt;b&gt;</b>"], TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextKeepsUnescapedWebVttCharacters(): void
    {
        $subtitle = TestSubtitles::fromTexts([">> TOM & JERRY", ">> A &amp; B"]);

        $subtitle->replaceText("TOM", "Tom &lt;")->replaceText("A", "a");

        $this->assertSame([">> Tom &amp;lt; & JERRY", ">> a &amp; B"], TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextWithRegex(): void
    {
        $subtitle = TestSubtitles::fromTexts(["Wait.....", "<i>colour</i> and COLOUR"]);

        $subtitle->replaceText('/\.{4,}/', "...", new ReplaceTextOptions(regex: true))->replaceText('/col(ou)r/', 'col$1r!', new ReplaceTextOptions(regex: true, caseSensitive: false));

        $this->assertSame(["Wait...", "<i>colour!</i> and colOUr!"], TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextCaseInsensitiveWithoutRegex(): void
    {
        $subtitle = TestSubtitles::fromTexts(["Ärger and ärger", "Price: \$1"]);

        $subtitle->replaceText("ÄRGER", "joy", new ReplaceTextOptions(caseSensitive: false))->replaceText("price", '$1 \1', new ReplaceTextOptions(caseSensitive: false));

        $this->assertSame(["joy and joy", '$1 \1: $1'], TestSubtitles::texts($subtitle));
    }


    public function testReplaceTextRejectsInvalidInput(): void
    {
        $subtitle = TestSubtitles::fromTexts(["text"]);

        try {
            $subtitle->replaceText("", "x");
            $this->fail("An empty search must throw.");
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $subtitle->replaceText("/(/", "x", new ReplaceTextOptions(regex: true));
    }


    public function testStripFormatting(): void
    {
        $texts = ["<b>Run</b>, <font color=\"#ff0000\">now</font>!", "<b><i>Run</i></b> &amp; <v Fred>hide"];

        $this->assertSame(["Run, now!", "Run &amp; hide"], TestSubtitles::texts(TestSubtitles::fromTexts([...$texts])->stripFormatting()));
        $this->assertSame(["Run, now!", "<i>Run</i> &amp; hide"],
                          TestSubtitles::texts(TestSubtitles::fromTexts([...$texts])->stripFormatting(["i"])));
    }


    public function testStripFormattingKeepsWordTimestamps(): void
    {
        $line = "<b>One</b> <00:00:01.500>two <00:00:02.000><i>three</i>";

        $this->assertSame(["One <00:00:01.500>two <00:00:02.000>three"],
                          TestSubtitles::texts(TestSubtitles::fromTexts([$line])->stripFormatting()));
        $this->assertSame(["One two <i>three</i>"], TestSubtitles::texts(TestSubtitles::fromTexts([$line])->stripFormatting(["i"], false)));
    }


    public function testMapTextGetsDecodedTextRunsAndTheCue(): void
    {
        $subtitle = TestSubtitles::fromTexts(["<i>a &amp; b</i> c", "&lt;d&gt;"]);
        $calls    = [];

        $subtitle->mapText(function (string $text, SubtitleCue $cue) use (&$calls): string {
            $calls[] = [$text, $cue->getStart()];

            return "[$text]";
        });

        $this->assertSame([["a & b", 0.0], [" c", 0.0], ["<d>", 1.0]], $calls);
        $this->assertSame(["<i>[a &amp; b]</i>[ c]", "[&lt;d&gt;]"], TestSubtitles::texts($subtitle));
    }


    public function testMapTextKeepsUnchangedRunsByteForByte(): void
    {
        $subtitle = TestSubtitles::fromTexts(["a&nbsp;b <i>&#39;c&#39;</i>"]);

        $subtitle->mapText(fn (string $text): string => $text);

        $this->assertSame(["a&nbsp;b <i>&#39;c&#39;</i>"], TestSubtitles::texts($subtitle));
    }


    public function testMapLinesGetsFullLines(): void
    {
        $subtitle = TestSubtitles::fromTexts(["one\n<i>two</i>"]);

        $subtitle->mapLines(fn (string $line, SubtitleCue $cue): string => "<font color=\"#ffff00\">$line</font>");

        $this->assertSame(["<font color=\"#ffff00\">one</font>\n<font color=\"#ffff00\"><i>two</i></font>"],
                          TestSubtitles::texts($subtitle));
    }


    public function testRemovesCuesThatBecomeEmptyAndKeepsComments(): void
    {
        $subtitle = TestSubtitles::fromTexts(["first", "<i>[MUSIC]</i>", "[DOOR]\n[MUSIC]", "last"]);
        $subtitle->addCue(new SubtitleCue(10, 11, ""));
        $subtitle->addComment("before music", 1)->addComment("before door", 2)->addComment("before last", 3);

        $subtitle->replaceText('/\[\w+\]/', "", new ReplaceTextOptions(regex: true));

        $this->assertSame(["first", "last", ""], TestSubtitles::texts($subtitle));
        $this->assertEquals([
            new Comment("before music", 1),
            new Comment("before door", 1),
            new Comment("before last", 1),
        ], $subtitle->getComments());
        $this->assertSame([], $subtitle->validate(ValidationRules::structure()));
    }


    public function testToUpperOrLowerChangesPlainText(): void
    {
        $this->assertSame("O'NEIL & CO", Subtitle::toUpperOrLower("O'Neil & Co", true));
        $this->assertSame("o'neil & co", Subtitle::toUpperOrLower("O'Neil & Co", false));
    }
}
