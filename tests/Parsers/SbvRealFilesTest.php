<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\SbvFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class SbvRealFilesTest extends TestCase
{
    public static function realFiles(): array
    {
        return [
            "subsrt shape" => [
                "subsrt_shape.sbv",
                5,
                [0.48, 3.95, ">> MARA: Good morning, this is Mara Lind[br]and this is Tom Berg"],
                [16.3, 21.15, "First, we put the flour and the water on the table"],
            ],
            "YouTube Studio LF" => [
                "youtube_studio_lf.sbv",
                9,
                [0.0, 2.24, "[Music]"],
                [3600.0, 3603.5, "That was the weather. Thank you for watching.\n[Applause]"],
            ],
            "YouTube Studio CR LF" => [
                "youtube_studio_crlf.sbv",
                6,
                [1.5, 4.0, "The 7:15 train to the harbour leaves from platform 2."],
                [18.43, 21.0, "The next stop is Market Square."],
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
        $formatted = $subtitle->format(SbvFormatter::class);
        $reparsed  = Subtitle::parse($formatted, SbvParser::class);

        $this->assertSame(
            array_map($this->describeCue(...), $subtitle->getCues()),
            array_map($this->describeCue(...), $reparsed->getCues())
        );
        $this->assertSame($formatted, $reparsed->format(SbvFormatter::class));
    }


    #[DataProvider("realFiles")]
    public function testRealFileFormatsToItsOwnTextWithLfEndings(string $fileName): void
    {
        $expected = rtrim(str_replace("\r\n", "\n", file_get_contents(__DIR__ . "/../files/sbv/real/" . $fileName)), "\n") . "\n";

        $this->assertSame($expected, $this->parseFile($fileName)->format(SbvFormatter::class));
    }


    private function parseFile(string $fileName): Subtitle
    {
        return Subtitle::parse(file_get_contents(__DIR__ . "/../files/sbv/real/" . $fileName), SbvParser::class);
    }


    private function describeCue(SubtitleCue $cue): array
    {
        return [$cue->getStart(), $cue->getEnd(), $cue->getLines()];
    }
}
