<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\SubRipFormatter;
use SubtitleToolbox\Markup;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SubRipRealFilesTest extends TestCase
{
    public static function realFiles(): array
    {
        return [
            "Own styled" => [
                "own_styled.srt",
                10,
                [17.985, 20.521, "<font color=\"#00ff00\"><b>[train horn]</b></font>"],
                [720.0, 780.0, "<i>x</i>^3 * <i>x</i> = 100"],
            ],
            "language-subtitles dots tester" => [
                "language_subtitles_dots_tester.srt",
                3,
                [2.501, 4.5, "Tester for dots instead of commas in timestamps 1.0 by ale5000\n<b><i>Use VLC 1.0.3 or higher as reference</i></b>"],
                [6.501, 8.5, "OK"],
            ],
            "language-subtitles SSA extensions" => [
                "language_subtitles_ssa_extensions.srt",
                19,
                [1.0, 4.0, "Top-left: an7"],
                [97.0, 105.0, "Back to default"],
            ],
            "Own alignment and coordinates" => [
                "own_alignment_and_coordinates.srt",
                10,
                [1.0, 3.0, "Plain first cue"],
                [19.5, 21.0, "Keep this: {\\some_unknown_tag} and {normal text}"],
            ],
            "Own CR CR LF line endings" => [
                "own_cr_cr_lf.srt",
                3,
                [1.0, 2.5, "Every line in this file\nends with CR CR LF"],
                [5.0, 6.5, "Last cue"],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($fileName)->getCues();

        $this->assertSame($cueCount, count($cues));
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTrip(string $fileName): void
    {
        $subtitle  = $this->parseFile($fileName);
        $formatted = $subtitle->format(SubRipFormatter::class);
        $reparsed  = Subtitle::parse($formatted, SubRipParser::class);

        $this->assertSame(
            array_map($this->describeCue(...), $subtitle->getCues()),
            array_map($this->describeCue(...), $reparsed->getCues())
        );
        $this->assertSame($formatted, $reparsed->format(SubRipFormatter::class));
    }


    public function testOwnFileKeepsCoordinatesAndAlignment(): void
    {
        $cues = $this->parseFile("own_alignment_and_coordinates.srt")->getCues();

        $this->assertSame(["coordinates" => ["x1" => 0, "x2" => 0, "y1" => 50, "y2" => 100]], $cues[1]->getFormatData("srt"));
        $this->assertSame(
            [null, null, 8, 4, 7, 8, 9, null, null, null],
            array_map(fn(SubtitleCue $cue) => $cue->getAlignment(), $cues)
        );
        $this->assertSame(["Middle left from the", "middle of a line, the second tag is ignored"], $cues[3]->getLines());
        $this->assertSame(["<i>Italic</i> and <b>bold</b>", "<s>struck</s> and <u>underlined</u>"], $cues[7]->getLines());
    }


    public function testOwnFileIsFormattedWithStandardTimestampsAndLeadingAlignment(): void
    {
        $this->assertSame(
            file_get_contents(__DIR__ . "/../files/srt/real/own_alignment_and_coordinates_formatted.srt"),
            $this->parseFile("own_alignment_and_coordinates.srt")->format(SubRipFormatter::class)
        );
    }


    public function testSsaExtensionsSetTheAlignment(): void
    {
        $cues = $this->parseFile("language_subtitles_ssa_extensions.srt")->getCues();

        $this->assertSame(
            [7, 8, 9, 4, 5, 6, 1, 2, 3, 1, 2, 3, 7, 8, 9, 4, 5, 6, null],
            array_map(fn(SubtitleCue $cue) => $cue->getAlignment(), $cues)
        );
        $this->assertSame(["[Deprecated] Middle-centre: \\a10"], $cues[16]->getLines());
    }


    private function parseFile(string $fileName): Subtitle
    {
        return Subtitle::parse(file_get_contents(__DIR__ . "/../files/srt/real/$fileName"), SubRipParser::class);
    }


    private function describeCue(SubtitleCue $cue): array
    {
        // The formatter writes no tag for bottom center, so 2 comes back as the format default null.
        $alignment = $cue->getAlignment() === 2 ? null : $cue->getAlignment();

        // The formatter strips tags outside the SubRip set, for example <foo></foo>.
        $lines = array_values(array_filter(
            explode("\n", Markup::keepTags($cue->getText(), ["b", "u", "i", "s", "font"])),
            fn(string $line) => trim($line) !== ""
        ));

        return [$cue->getStart(), $cue->getEnd(), $lines, $alignment, $cue->getFormatData("srt")];
    }
}
