<?php

namespace SubtitleToolbox\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Format;
use SubtitleToolbox\Subtitle;
use SubtitleToolbox\SubtitleCue;

class IttRealFileTest extends TestCase
{
    private const DIR = __DIR__ . "/../files/itt/real/";


    public static function realFileProvider(): array
    {
        return [
            "avscript_testing"   => ["avscript_testing.itt", 6, 3.003, 123.123, "Testing testing", null, 616.616, 741.741, "Testing testing", null],
            "fcp_23976_styles"   => ["fcp_23976_styles.itt", 8, 36 / 23.976, 96 / 23.976, "The bakery opens at six.", 2, (21 * 24 + 2) / 23.976, (23 * 24 + 22) / 23.976, "<i>The shop closes at noon.</i>", 2],
            "bom_2997_crlf"      => ["bom_2997_crlf.itt", 6, 3598 * 30 / 29.97, (3600 * 30 + 15) / 29.97, "Guten Abend, hier ist das Wetter.", 2, 3612 * 30 / 29.97, (3614 * 30 + 29) / 29.97, "Das war das Wetter. Gute Nacht.", 2],
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
        $cues = array_values(Subtitle::fromString(file_get_contents(self::DIR . $file), Format::Itt)->getCues());

        $this->assertCount($cueCount, $cues);
        $this->assertEqualsWithDelta([$firstStart, $firstEnd], [$cues[0]->getStart(), $cues[0]->getEnd()], 0.001);
        $this->assertSame([$firstText, $firstAlignment], [$cues[0]->getText(), $cues[0]->getAlignment()]);
        $last = end($cues);
        $this->assertEqualsWithDelta([$lastStart, $lastEnd], [$last->getStart(), $last->getEnd()], 0.001);
        $this->assertSame([$lastText, $lastAlignment], [$last->getText(), $last->getAlignment()]);
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileDetectsAsTtml(string $file): void
    {
        $this->assertSame(Format::Ttml, Format::detect(file_get_contents(self::DIR . $file)));
    }


    #[DataProvider("realFileProvider")]
    public function testRealFileRoundTripKeepsCues(string $file): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . $file), Format::Itt);
        $output   = $subtitle->toString(Format::Itt);
        $reparsed = Subtitle::fromString($output, Format::Itt);

        $this->assertSame(count($subtitle->getCues()), count($reparsed->getCues()));
        foreach ($subtitle->getCues() as $idx => $cue) {
            $copy = $reparsed->getCues()[$idx];
            $this->assertEqualsWithDelta([$cue->getStart(), $cue->getEnd()], [$copy->getStart(), $copy->getEnd()], 0.001);
            $this->assertSame($cue->getText(), $copy->getText());
            $this->assertSame($cue->getAlignment() ?? 2, $copy->getAlignment());
        }
        $this->assertSame($subtitle->getAllMetadata(), $reparsed->getAllMetadata());
        $this->assertSame($subtitle->getFormatData("itt"), $reparsed->getFormatData("itt"));
        $this->assertSame($output, $reparsed->toString(Format::Itt));
    }


    public function testRealFileFormatData(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "avscript_testing.itt"), Format::Itt);

        $this->assertSame(
            ["timeBase" => "smpte", "frameRate" => "24", "frameRateMultiplier" => "1000 1001", "dropMode" => "nonDrop"],
            $subtitle->getFormatData("itt")
        );
        $this->assertSame("en", $subtitle->getMetadata(Subtitle::METADATA_LANGUAGE));
    }


    public function testRealFileWithBomKeepsTitleLanguageAndStyles(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "bom_2997_crlf.itt"), Format::Itt);
        $cues     = $subtitle->getCues();

        $this->assertSame("Wetterbericht", $subtitle->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("de-DE", $subtitle->getMetadata(Subtitle::METADATA_LANGUAGE));
        $this->assertSame(["Im Norden bleibt es kühl,", "im Süden scheint die Sonne."], $cues[1]->getLines());
        $this->assertSame([8, "<i>Grafik: Temperaturen</i>"], [$cues[2]->getAlignment(), $cues[2]->getText()]);
        $this->assertSame("Am Wochenende wird es <b>wärmer</b>.", $cues[4]->getText());
    }


    public function testRealFileFinalCutProStyles(): void
    {
        $cues = Subtitle::fromString(file_get_contents(self::DIR . "fcp_23976_styles.itt"), Format::Itt)->getCues();

        $this->assertSame([8, "<i>[oven door creaks]</i>"], [$cues[2]->getAlignment(), $cues[2]->getText()]);
        $this->assertSame("Wheat bread takes <u>two</u>.", $cues[4]->getText());
        $this->assertSame("<font color=\"#ffff00\">BAKER:</font> Mind the hot tray.", $cues[5]->getText());
    }
}
