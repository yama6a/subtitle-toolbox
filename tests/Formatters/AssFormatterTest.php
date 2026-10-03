<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\AssParser;
use SubtitleToolbox\StringHelpers;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class AssFormatterTest extends TestCase
{
    private const DEFAULT_HEADER = "\xEF\xBB\xBF[Script Info]\n" .
                                   "ScriptType: v4.00+\n" .
                                   "PlayResX: 384\n" .
                                   "PlayResY: 288\n" .
                                   "ScaledBorderAndShadow: yes\n" .
                                   "\n" .
                                   "[V4+ Styles]\n" .
                                   "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, " .
                                   "Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, " .
                                   "Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n" .
                                   "Style: Default,Arial,16,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,0,0,0,0,100,100,0,0,1,1,0,2,10,10,10,1\n" .
                                   "\n" .
                                   "[Events]\n" .
                                   "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";


    public static function realFiles(): array
    {
        return [
            ["own_aegisub.ass"],
            ["own_ffmpeg.ass"],
            ["own_signs_crlf.ass"],
            ["own_ssa_v4.ssa"],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTrip(string $file): void
    {
        $subtitle  = $this->parseFile($file);
        $formatted = $subtitle->format(AssFormatter::class);
        $reparsed  = Subtitle::parse($formatted, AssParser::class);

        $this->assertSame(
            array_map($this->describeCue(...), $subtitle->getCues()),
            array_map($this->describeCue(...), $reparsed->getCues())
        );
        $this->assertSame($subtitle->getComments(), $reparsed->getComments());
        $this->assertSame($subtitle->getAllMetadata(), $reparsed->getAllMetadata());
        $this->assertSame($subtitle->getFormatData("ass"), $reparsed->getFormatData("ass"));
        $this->assertSame($formatted, $reparsed->format(AssFormatter::class));
    }


    public static function sortedRealFiles(): array
    {
        return [
            ["own_aegisub.ass"],
            ["own_ffmpeg.ass"],
            ["own_ssa_v4.ssa"],
        ];
    }


    #[DataProvider("sortedRealFiles")]
    public function testSortedRealFileIsWrittenBackWithBomAndLineFeeds(string $file): void
    {
        $raw = file_get_contents(__DIR__ . "/../files/ass/real/$file");

        $this->assertSame(
            StringHelpers::addUtf8Bom(StringHelpers::normalizeEOLs($raw)),
            $this->parseFile($file)->format(AssFormatter::class)
        );
    }


    public function testUnsortedEventsAreWrittenInTimeOrder(): void
    {
        $formatted = $this->parseFile("own_signs_crlf.ass")->format(AssFormatter::class);

        $this->assertStringContainsString(
            "Dialogue: 0,0:00:04.50,0:00:07.50,Default,Reporter,0,0,0,,Clouds move in from the west\\nduring the night.\n" .
            "Dialogue: 1,0:00:05.00,0:00:09.00,Sign,,0,0,0,,{\\pos(640,90)\\fad(200,200)\\t(0,500,\\1c&H00FFFF&)}SUNNY{\\fs24}\\N{\\fs}25 °C\n",
            $formatted
        );
        $this->assertStringContainsString("[Fonts]\nfontname: WeatherSans_0.ttf\n", $formatted);
    }


    public function testCuesFromOtherFormatsGetAMinimalHeader(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2.5, ["<i>Hello</i>", "world"]))
            ->addCue((new SubtitleCue(3.004, 4.996, "Top"))->setAlignment(8));

        $this->assertSame(
            self::DEFAULT_HEADER .
            "Dialogue: 0,0:00:01.00,0:00:02.50,Default,,0,0,0,,{\\i1}Hello{\\i0}\\Nworld\n" .
            "Dialogue: 0,0:00:03.00,0:00:05.00,Default,,0,0,0,,{\\an8}Top\n",
            $subtitle->format(AssFormatter::class)
        );
    }


    public static function coreMarkup(): array
    {
        return [
            "underline and strike"    => [["<u>a</u> <s>b</s>"], "{\\u1}a{\\u0} {\\s1}b{\\s0}"],
            "colour as BGR"           => [["<font color=\"#FF8000\">a</font>"], "{\\c&H0080FF&}a{\\c}"],
            "single quoted colour"    => [["<font color='#FF8000'>a</font>"], "{\\c&H0080FF&}a{\\c}"],
            "inner colour restores"   => [["<font color=\"#ff0000\">a<font color=\"#00ff00\">b</font>c</font>"], "{\\c&H0000FF&}a{\\c&H00FF00&}b{\\c&H0000FF&}c{\\c}"],
            "font without colour"     => [["<font face=\"Arial\">a</font>"], "a"],
            "last tag of a kind wins" => [["<b>a</b><b>b</b>"], "{\\b1}a{\\b1}b{\\b0}"],
            "other tags are stripped" => [["<c.yellow>a</c> <lang en>b</lang>"], "a b"],
            "entities are decoded"    => [["1 &lt; 2 &amp;&amp; 3&nbsp;&gt; 2"], "1 < 2 && 3\\h> 2"],
            "speaker"                 => [["<v.loud Fred, Jr.>Hi", "there"], "Hi\\Nthere", "Fred Jr."],
        ];
    }


    #[DataProvider("coreMarkup")]
    public function testCoreMarkupBecomesOverrideTags(array $lines, string $text, string $name = ""): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(1, 2, $lines));

        $this->assertSame(
            self::DEFAULT_HEADER . "Dialogue: 0,0:00:01.00,0:00:02.00,Default,$name,0,0,0,,$text\n",
            $subtitle->format(AssFormatter::class)
        );
    }


    public static function wordTimestamps(): array
    {
        return [
            "timestamp at the start" => ["<00:00:10.000>Ka<00:00:10.500>ra<00:00:10.754>o", "{\\k50}Ka{\\k25}ra{\\k125}o"],
            "text before the first"  => ["Ka<00:00:10.300>ra", "{\\k30}Ka{\\k170}ra"],
            "timestamp before start" => ["<00:00:09.000>Ka<00:00:11.000>ra", "{\\k100}Ka{\\k100}ra"],
            "timestamp after end"    => ["<00:00:10.000>Ka<00:00:13.000>ra", "{\\k300}Ka{\\k0}ra"],
        ];
    }


    #[DataProvider("wordTimestamps")]
    public function testWordTimestampsBecomeKaraokeTags(string $line, string $text): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(10, 12, $line));

        $this->assertSame(
            self::DEFAULT_HEADER . "Dialogue: 0,0:00:10.00,0:00:12.00,Default,,0,0,0,,$text\n",
            $subtitle->format(AssFormatter::class)
        );
    }


    public function testUnchangedCueKeepsItsOriginalText(): void
    {
        $subtitle = $this->parseFile("own_signs_crlf.ass");
        $subtitle->shift(1);

        $this->assertStringContainsString(
            "Dialogue: 0,0:00:06.00,0:00:10.00,Sign,,0,0,0,,{\\an7\\pos(40,40)\\p1\\bord0\\c&HFFFFFF&}m 0 0 l 200 0 200 60 0 60{\\p0}\n",
            $subtitle->format(AssFormatter::class)
        );
    }


    public function testChangedCueIsWrittenFromItsLines(): void
    {
        $subtitle = $this->parseFile("own_aegisub.ass");
        $cues     = $subtitle->getCues();
        $cues[2]->setLines(["<v Guard>Is it <i>late</i> today?"]);
        $cues[3]->setAlignment(7);

        $formatted = $subtitle->format(AssFormatter::class);

        $this->assertStringContainsString("Dialogue: 0,0:00:06.30,0:00:08.00,Default,Guard,0,0,0,,Is it {\\i1}late{\\i0} today?\n", $formatted);
        $this->assertStringContainsString("Dialogue: 0,0:00:08.10,0:00:10.90,Top,,0,0,0,,{\\an7}Platform 4: {\\c&H00D7FF&}Coast Express{\\c}\n", $formatted);
    }


    public function testSsaUsesLegacyAlignmentAndKeepsMarkedColumn(): void
    {
        $subtitle = $this->parseFile("own_ssa_v4.ssa");
        $subtitle->getCues()[1]->setAlignment(4);

        $this->assertStringContainsString(
            "Dialogue: Marked=0,0:00:05.50,0:00:08.00,Notice,,0000,0000,0000,,{\\a9}Next ferry: 9:00\n",
            $subtitle->format(AssFormatter::class)
        );
    }


    public function testStripAllTagsKeepsAlignmentAndSpeaker(): void
    {
        $subtitle = $this->parseFile("own_aegisub.ass");

        $formatted = $subtitle->format(AssFormatter::class, [SubtitleFormatter::OPTION_STRIP_ALL_XML_TAGS]);

        $this->assertStringContainsString("Dialogue: 0,0:00:06.30,0:00:08.00,Default,Passenger,0,0,0,,Is it on time today?\n", $formatted);
        $this->assertStringContainsString("Dialogue: 0,0:00:08.10,0:00:10.90,Top,,0,0,0,,{\\an8}Platform 4: Coast Express\n", $formatted);
    }


    public function testTitleAndNewCommentsAreWritten(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2, "a"))
            ->setMetadata(Subtitle::METADATA_TITLE, "Bakery\nnews")
            ->addComment("first", 0)
            ->addComment("last\nline", 1);

        $formatted = $subtitle->format(AssFormatter::class);

        $this->assertStringStartsWith("\xEF\xBB\xBF[Script Info]\nTitle: Bakery news\nScriptType: v4.00+\n", $formatted);
        $this->assertStringEndsWith(
            "Comment: 0,0:00:01.00,0:00:01.00,Default,,0,0,0,,first\n" .
            "Dialogue: 0,0:00:01.00,0:00:02.00,Default,,0,0,0,,a\n" .
            "Comment: 0,0:00:02.00,0:00:02.00,Default,,0,0,0,,last\\Nline\n",
            $formatted
        );
    }


    public function testTimesAreRoundedToCentiseconds(): void
    {
        $subtitle = (new Subtitle())->addCue(new SubtitleCue(3599.996, 36000.004, "a"));

        $this->assertStringEndsWith(
            "Dialogue: 0,1:00:00.00,10:00:00.00,Default,,0,0,0,,a\n",
            $subtitle->format(AssFormatter::class)
        );
    }


    private function parseFile(string $file): Subtitle
    {
        return Subtitle::parse(file_get_contents(__DIR__ . "/../files/ass/real/$file"), AssParser::class);
    }


    private function describeCue(SubtitleCue $cue): array
    {
        return [$cue->getStart(), $cue->getEnd(), $cue->getLines(), $cue->getAlignment(), $cue->getFormatData("ass")];
    }
}
