<?php

namespace SubtitleToolbox\Formatters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Parsers\SamiParser;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SamiFormatterTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/sami/real/";


    public static function realFiles(): array
    {
        return [
            ["mantas_smi.smi", null],
            ["mantas_smi_formatted.smi", null],
            ["multi_language.smi", null],
            ["multi_language.smi", "ENCC"],
            ["multi_language.smi", "FRCC"],
            ["pysubs2_source_id.smi", null],
            ["subsrt_sample.smi", null],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileRoundTrips(string $file, ?string $class): void
    {
        $subtitle = (new SamiParser($class))->parse(file_get_contents(self::DIR . $file));
        $output   = $subtitle->format(SamiFormatter::class);
        $reparsed = Subtitle::parse($output, SamiParser::class);

        $this->assertSame($this->describe($subtitle), $this->describe($reparsed));
        $this->assertSame($subtitle->getAllMetadata(), $reparsed->getAllMetadata());
        $this->assertSame($output, $reparsed->format(SamiFormatter::class));
    }


    public function testWritesTheChosenClassOnly(): void
    {
        $output = (new SamiParser("FRCC"))->parse(file_get_contents(self::DIR . "multi_language.smi"))->format(SamiFormatter::class);

        $this->assertSame(
            "<SAMI>\n<HEAD>\n<TITLE>Bakery Tour</TITLE>\n<STYLE TYPE=\"text/css\">\n<!--\n" .
            "P { margin-left:8pt; margin-right:8pt; font-size:20pt; text-align:center;\n" .
            "    font-family:굴림, Arial; color:white; background-color:black; }\n" .
            ".FRCC { Name:Français; lang:fr-FR; SAMIType:CC; }\n-->\n</STYLE>\n</HEAD>\n<BODY>\n" .
            "<SYNC Start=1000><P Class=FRCC>La boulangerie ouvre à six heures du matin.\n" .
            "<SYNC Start=4200><P Class=FRCC>&nbsp;\n" .
            "<SYNC Start=5000><P Class=FRCC>Il <font color=\"yellow\">pleut</font> aujourd'hui.<br>Prenez un parapluie.\n" .
            "<SYNC Start=8000><P Class=FRCC><u>Le train</u> part bientôt.\n" .
            "<SYNC Start=10500><P Class=FRCC>&nbsp;\n" .
            "</BODY>\n</SAMI>\n",
            $output
        );
    }


    public function testCuesFromAnotherFormat(): void
    {
        $subtitle = (new Subtitle())
            ->addCue(new SubtitleCue(1, 2.5, ["<v Anna>The <b>bakery</b> &amp; café", "<font color=\"#ff0000\">opens</font> <00:00:02.000>at six"]))
            ->addCue(new SubtitleCue(2.5, 4, "Line\u{00A0}one"))
            ->addCue(new SubtitleCue(5, 6, []));

        $this->assertSame(
            "<SAMI>\n<HEAD>\n<STYLE TYPE=\"text/css\"><!--\nP { font-family: Arial; text-align: center; }\n.SUBTTL { Name: Subtitles; }\n--></STYLE>\n</HEAD>\n<BODY>\n" .
            "<SYNC Start=1000><P Class=SUBTTL>The <b>bakery</b> &amp; café<br><font color=\"#ff0000\">opens</font> at six\n" .
            "<SYNC Start=2500><P Class=SUBTTL>Line&nbsp;one\n" .
            "<SYNC Start=4000><P Class=SUBTTL>&nbsp;\n" .
            "<SYNC Start=5000><P Class=SUBTTL>&nbsp;\n" .
            "<SYNC Start=6000><P Class=SUBTTL>&nbsp;\n" .
            "</BODY>\n</SAMI>\n",
            $subtitle->format(SamiFormatter::class)
        );
    }


    public function testLanguageAndTitleMetadata(): void
    {
        $subtitle = (new Subtitle())
            ->setMetadata(Subtitle::METADATA_LANGUAGE, "ko-KR")
            ->setMetadata(Subtitle::METADATA_TITLE, "Rain & <Sun>")
            ->addCue(new SubtitleCue(0, 1, "비가 옵니다"));

        $output = $subtitle->format(SamiFormatter::class);

        $this->assertStringContainsString("<TITLE>Rain &amp; &lt;Sun&gt;</TITLE>\n", $output);
        $this->assertStringContainsString("\n.KOKRCC { Name: ko-KR; lang: ko-KR; }\n", $output);
        $this->assertStringContainsString("<SYNC Start=0><P Class=KOKRCC>비가 옵니다\n", $output);

        $reparsed = Subtitle::parse($output, SamiParser::class);
        $this->assertSame(["title" => "Rain & <Sun>", "language" => "ko-KR"], $reparsed->getAllMetadata());
    }


    public function testChangedCueIsWrittenFromCoreMarkup(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "pysubs2_source_id.smi"), SamiParser::class);
        $cues     = $subtitle->getCues();
        end($cues)->setLines(["<i>End</i> of the report"]);

        $this->assertStringContainsString(
            "<SYNC Start=73000><P Class=ENUSCC><i>End</i> of the report\n<SYNC Start=83000><P Class=ENUSCC>&nbsp;\n",
            $subtitle->format(SamiFormatter::class)
        );
    }


    public function testStripAllXmlTagsOption(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "subsrt_sample.smi"), SamiParser::class);

        $this->assertStringContainsString(
            "<SYNC Start=335453><P Class=KR>출발! 출발!\n",
            $subtitle->format(SamiFormatter::class, [SamiFormatter::OPTION_STRIP_ALL_XML_TAGS])
        );
    }


    public function testFileWithoutClassesWritesParagraphsWithoutClass(): void
    {
        $raw = "<SAMI><HEAD><STYLE><!-- P { color: white; } --></STYLE></HEAD><BODY><SYNC Start=0><P>one<SYNC Start=900><P>&nbsp;</BODY></SAMI>";

        $this->assertSame(
            "<SAMI>\n<HEAD>\n<STYLE TYPE=\"text/css\"><!-- P { color: white; } --></STYLE>\n</HEAD>\n<BODY>\n" .
            "<SYNC Start=0><P>one\n<SYNC Start=900><P>&nbsp;\n</BODY>\n</SAMI>\n",
            Subtitle::parse($raw, SamiParser::class)->format(SamiFormatter::class)
        );
    }


    private function describe(Subtitle $subtitle): array
    {
        return array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd(), $cue->getLines()], $subtitle->getCues());
    }
}
