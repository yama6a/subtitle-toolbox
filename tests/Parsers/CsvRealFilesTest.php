<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Formatters\CsvFormatter;
use SubtitleToolbox\Formatters\SubtitleFormatter;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class CsvRealFilesTest extends TestCase
{
    public static function realFiles(): array
    {
        return [
            "mantas-done shape" => [
                "mantas_done_shape.csv",
                new CsvColumns(),
                5,
                [1.5, 4.0, "The bakery opens at seven, every day."],
                [16.0, 18.2, "Please queue at the door, not inside."],
                ["lineEnding" => "\n", "bom" => false],
            ],
            "Excel German locale" => [
                "excel_de_semicolon.csv",
                new CsvColumns(end: "Ende", speaker: "Sprecher"),
                4,
                [2.0, 4.5, "<v Lena>Der Zug nach Basel fährt um 7:15 Uhr."],
                [12.0, 14.64, "<v Lena>Danke; bis morgen!"],
                ["lineEnding" => "\r\n", "bom" => true],
            ],
            "dubbing script" => [
                "dubbing_script.csv",
                new CsvColumns(start: "Start TC", text: "Text", speaker: "Character", frameRate: 25),
                4,
                [36001.48, 36004.0, "<v NARRATOR>The weather turns cold tonight."],
                [36008.2, 36018.2, "<v NARRATOR>Snow falls in the hills, rain in the valley."],
                ["lineEnding" => "\n", "bom" => false],
            ],
            "spreadsheet TSV" => [
                "sheets_export.tsv",
                new CsvColumns(),
                4,
                [0.5, 3.0, "Welcome to the station."],
                [10.0, 12.0, "Have a safe trip."],
                ["lineEnding" => "\n", "bom" => false],
            ],
        ];
    }


    #[DataProvider("realFiles")]
    public function testRealFileParses(string $fileName, CsvColumns $columns, int $cueCount, array $firstCue, array $lastCue): void
    {
        $cues = $this->parseFile($fileName, $columns)->getCues();

        $this->assertSame($cueCount, count($cues));
        $this->assertSame($firstCue, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $last = $cues[count($cues) - 1];
        $this->assertSame($lastCue, [$last->getStart(), $last->getEnd(), $last->getText()]);
    }


    #[DataProvider("realFiles")]
    public function testRealFileFormatsToItsOwnBytes(string $fileName, CsvColumns $columns, int $cueCount, array $firstCue, array $lastCue, array $options): void
    {
        $content = file_get_contents(__DIR__ . "/../files/csv/real/$fileName");

        $this->assertSame($content, $this->parseFile($fileName, $columns)->format(CsvFormatter::class, [
            SubtitleFormatter::OPTION_LINE_ENDING => $options["lineEnding"],
            SubtitleFormatter::OPTION_BOM         => $options["bom"],
        ]));
    }


    #[DataProvider("realFiles")]
    public function testRealFileSurvivesARoundTripInTheDefaultLayout(string $fileName, CsvColumns $columns): void
    {
        $subtitle = $this->parseFile($fileName, $columns)->setFormatData("csv", []);
        $fresh    = Subtitle::parse($subtitle->format(CsvFormatter::class), new CsvParser());

        $this->assertSame(array_map($this->describeCue(...), $subtitle->getCues()), array_map($this->describeCue(...), $fresh->getCues()));
    }


    public function testDubbingScriptKeepsTheNotesColumn(): void
    {
        $cues = $this->parseFile("dubbing_script.csv", new CsvColumns(start: "Start TC", text: "Text", speaker: "Character", frameRate: 25))->getCues();

        $this->assertSame(["columns" => ["Notes" => "warm tone"]], $cues[1]->getFormatData("csv"));
        $this->assertSame(36005.8, $cues[2]->getStart());
    }


    public function testTsvKeepsTheIdentifiers(): void
    {
        $cues = $this->parseFile("sheets_export.tsv", new CsvColumns())->getCues();

        $this->assertSame(["intro", "platform", null, "end"], array_map(fn (SubtitleCue $cue): ?string => $cue->getIdentifier(), $cues));
    }


    private function parseFile(string $fileName, CsvColumns $columns): Subtitle
    {
        return Subtitle::parse(file_get_contents(__DIR__ . "/../files/csv/real/$fileName"), new CsvParser($columns));
    }


    private function describeCue(SubtitleCue $cue): array
    {
        return [$cue->getStart(), $cue->getEnd(), $cue->getLines(), $cue->getIdentifier()];
    }
}
