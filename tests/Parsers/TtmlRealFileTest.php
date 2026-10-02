<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\TtmlFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class TtmlRealFileTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/ttml/real/";


    public static function realFileProvider(): array
    {
        return [
            "astisub_breaklines"         => ["astisub_breaklines.ttml", 4, 0.0, 1.0, "First line\nSecond line", null, 3.0, 4.0, "Seventh line\nEighth middle line", null],
            "astisub_merging_style"      => ["astisub_merging_style.ttml", 4, 0.0, 60.0, "text1.0 text1.1", 8, 120.0, 180.0, "text3", 8],
            "astisub_smpte"              => ["astisub_smpte.ttml", 6, 99.0, 101.04, "(light rain)", 8, 151.4, 153.44, "<font color=\"#ffff00\"><i>(music for the</i></font>\ntraffic news)", null],
            "bbc_ebu_tt_d"               => ["bbc_ebu_tt_d.ttml", 20, 10.0, 13.0, "<v Anna>Good morning from the harbour.", 2, 76.12, 79.52, "<i>(music)</i>", 8],
            "mantas_dfxp_br"             => ["mantas_dfxp_br.dfxp", 1, 0.0, 1.0, "one\ntwo\nthree", 2, 0.0, 1.0, "one\ntwo\nthree", 2],
            "mantas_duplicated_ids"      => ["mantas_duplicated_ids.ttml", 3, 0.0, 1.0, "First line.", null, 2.0, 3.0, "Third line.", null],
            "mantas_fps_multiplier"      => ["mantas_fps_multiplier.ttml", 1, 15.015, 17.684, "First line.", null, 15.015, 17.684, "First line.", null],
            "mantas_multiple_divs"       => ["mantas_multiple_divs.ttml", 3, 1.464, 2.423, "The train to the coast\nleaves from platform four.", null, 10.886, 10.928, "BAKERY OPEN", null],
            "mantas_netflix_ticks"       => ["mantas_netflix_ticks.dfxp", 2, 137.4, 140.4, "The bakery's first bread\nis ready at six o'clock.", null, 3740.5, 3742.5, "The last train leaves at midnight.", null],
            "mantas_ttml2"               => ["mantas_ttml2.ttml", 5, 0.0, 2.0, "Hello I am your first line.", null, 8.0, 10.0, "<font color=\"#ff0000\">I am the last caption displayed in red and centered.</font>", 8],
            "pysubs2_regions"            => ["pysubs2_regions.ttml", 10, 1.375, 5.75, "TOP SAMPLE TEXT", 8, 45.325, 50.041, "for the weekend market.", 2],
            "w3c_dfxp_timing"            => ["w3c_dfxp_timing.dfxp", 4, 0.0, 2.0, "Text 1", 8, 1.0, 3.0, "Text 4", 8],
            "w3c_imsc11_frames"          => ["w3c_imsc11_frames.ttml", 3, 1.01, 3.0, "This should appear on frame 25.", null, 7.33, 9.0, "This should appear on frame 176.", null],
            "w3c_imsc11_line_gaps"       => ["w3c_imsc11_line_gaps.ttml", 1, 0.0, 30.0, "##Line gaps##\nThe quick brown fox\njumps over the <font color=\"#000000\">lazy </font>dog\n##Line gaps##", 2, 0.0, 30.0, "##Line gaps##\nThe quick brown fox\njumps over the <font color=\"#000000\">lazy </font>dog\n##Line gaps##", 2],
            "w3c_imsc11_paragraphs"      => ["w3c_imsc11_paragraphs.ttml", 4, 0.0, 30.0, "Paragraph 1", 2, 0.0, 30.0, "Paragraph 2", 8],
            "w3c_imsc11_regions"         => ["w3c_imsc11_regions.ttml", 3, 0.0, 6.0, "This region is within the editorial area.", 8, 0.0, 6.0, "This region is not.", 2],
            "w3c_ttml1_cells"            => ["w3c_ttml1_cells.ttml", 5, 0.0, 8.0, "Lorem ipsum dolor sit", null, 18.0, 29.0, "Ut enim ad minim veniam quis, nostrud", null],
            "w3c_ttml1_timed_spans"      => ["w3c_ttml1_timed_spans.ttml", 5, 0.0, 25.0, "Lorem ipsum dolor sit", null, 0.0, 25.0, "Ut enim ad minim veniam quis, nostrud", null],
            "w3c_ttml1_timing"           => ["w3c_ttml1_timing.ttml", 4, 0.0, 2.0, "Text 1", 8, 1.0, 3.0, "Text 4", 8],
            "smpte_drop_ntsc"            => ["smpte_drop_ntsc.ttml", 5, 57.391, 60.027, "The morning train leaves platform two.", 2, 3599.996, 3602.999, "The evening train runs on time.", 2],
        ];
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileParses(
        string $file,
        int $cueCount,
        float $firstStart,
        float $firstEnd,
        string $firstText,
        ?int $firstAlignment,
        float $lastStart,
        float $lastEnd,
        string $lastText,
        ?int $lastAlignment
    ): void {
        $cues = array_values(Subtitle::parse(file_get_contents(self::DIR . $file), TtmlParser::class)->getCues());

        $this->assertCount($cueCount, $cues);
        $this->assertSame([$firstStart, $firstEnd, $firstText, $firstAlignment], $this->describeCue($cues[0]));
        $this->assertSame([$lastStart, $lastEnd, $lastText, $lastAlignment], $this->describeCue(end($cues)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileRoundTripKeepsCues(string $file): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . $file), TtmlParser::class);
        $output   = $subtitle->format(TtmlFormatter::class);
        $reparsed = Subtitle::parse($output, TtmlParser::class);

        $this->assertSame(
            array_map($this->describeCue(...), $subtitle->getCues()),
            array_map($this->describeCue(...), $reparsed->getCues())
        );
        $this->assertSame($subtitle->getAllMetadata(), $reparsed->getAllMetadata());
        $this->assertSame($output, $reparsed->format(TtmlFormatter::class));
    }


    public function testRealFileSmpteDropFrameTimes(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "smpte_drop_ntsc.ttml"), TtmlParser::class);

        $this->assertSame(
            [[57.391, 60.027], [60.06, 63.497], [597.997, 599.999], [599.999, 602.669], [3599.996, 3602.999]],
            array_map(fn (SubtitleCue $cue): array => [$cue->getStart(), $cue->getEnd()], $subtitle->getCues())
        );
    }


    public function testRealFileEbuTtDAgentsTitleAndNestedSpans(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "bbc_ebu_tt_d.ttml"), TtmlParser::class);
        $cues     = array_values($subtitle->getCues());

        $this->assertSame("Harbour weather", $subtitle->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("en-GB", $subtitle->getMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame("sub3", $cues[2]->getIdentifier());
        $this->assertSame("<v Ben>The first ferry left\nat seven o'clock.", $cues[2]->getText());
        $this->assertSame("<v Ben>Tide tables are <b>on the <u>board</u></b>", $cues[10]->getText());
        $this->assertStringNotContainsString("ttm:title", $subtitle->getFormatData("ttml")["head"]);
        $this->assertStringContainsString("ebuttm:documentMetadata", $subtitle->getFormatData("ttml")["head"]);
    }


    public function testRealFileTicksAndPreservedSpace(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "mantas_netflix_ticks.dfxp"), TtmlParser::class);

        $this->assertSame(["region" => "bottomCenter"], $subtitle->getCues()[0]->getFormatData("ttml")["attributes"]);
        $this->assertSame(["xml:space" => "preserve"], $subtitle->getCues()[0]->getFormatData("ttml")["div"]);
    }


    public function testRealFileDfxpNamespaceIsWrittenBack(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "w3c_dfxp_timing.dfxp"), TtmlParser::class);
        $output   = $subtitle->format(TtmlFormatter::class);

        $this->assertStringContainsString("<tt xmlns=\"http://www.w3.org/2006/10/ttaf1\"", $output);
        $this->assertStringContainsString("<body tts:extent=\"640px 480px\" xml:id=\"b1\">", $output);
    }


    public function testRealFileWithoutNamespaceWritesNoInvalidIdentifier(): void
    {
        $subtitle = Subtitle::parse(file_get_contents(self::DIR . "astisub_breaklines.ttml"), TtmlParser::class);
        $output   = $subtitle->format(TtmlFormatter::class);

        $this->assertSame("1", $subtitle->getCues()[0]->getIdentifier());
        $this->assertStringContainsString("<p begin=\"00:00:00.000\" end=\"00:00:01.000\">First line<br/>Second line</p>", $output);
    }


    private function describeCue(SubtitleCue $cue): array
    {
        return [$cue->getStart(), $cue->getEnd(), $cue->getText(), $cue->getAlignment()];
    }
}
