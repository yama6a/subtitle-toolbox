<?php

namespace SubtitleToolbox;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SubtitleToolbox\Exceptions\ParsingException;
use SubtitleToolbox\Formatters\SubtitleFormatter;

class EncodingTest extends TestCase
{
    private const DIR = __DIR__ . "/files/encoding/";

    private const WINDOWS_OUTPUT = [
        SubtitleFormatter::OPTION_LINE_ENDING => "\r\n",
        SubtitleFormatter::OPTION_BOM         => false,
    ];


    public static function legacyFiles(): array
    {
        return [
            "French Windows-1252"  => [
                "french-windows-1252.srt", "Windows-1252", Format::SubRip,
                [1.0, 3.5, "Le café est fermé à midi."],
                [70.1, 72.9, "<i>Où êtes-vous, Noël ?</i>"],
            ],
            "Russian Windows-1251" => [
                "russian-windows-1251.srt", "Windows-1251", Format::SubRip,
                [2.0, 4.0, "Привет, как дела?"],
                [9.0, 11.5, "Ёлка стоит в углу."],
            ],
            "Japanese Shift_JIS"   => [
                "japanese-shift_jis.srt", "Shift_JIS", Format::SubRip,
                [1.2, 3.0, "こんにちは、元気ですか？"],
                [7.0, 9.8, "駅まで歩きましょう。"],
            ],
            "Korean CP949 SAMI"    => [
                "korean-cp949.smi", "CP949", Format::Sami,
                [1.0, 3.5, "안녕하세요.\n기차가 곧 도착합니다."],
                [7.0, 9.0, "<font color=\"#ffff00\">감사합니다.</font>"],
            ],
        ];
    }


    #[DataProvider("legacyFiles")]
    public function testLegacyFileParsesWithItsSourceEncoding(
        string $file, string $encoding, Format $format, array $first, array $last
    ): void {
        $raw      = file_get_contents(self::DIR . $file);
        $subtitle = Subtitle::fromString($raw, $format, $encoding);
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame($first, [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame($last, [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
        $this->assertSame($raw, iconv("UTF-8", $encoding, $subtitle->toString($format, self::WINDOWS_OUTPUT)));
    }


    #[DataProvider("legacyFiles")]
    public function testFormatDetectionWorksOnTheConvertedContent(string $file, string $encoding, Format $format): void
    {
        $detected = Subtitle::fromStringAutoDetectFormat(file_get_contents(self::DIR . $file), $encoding);

        $this->assertEquals(Subtitle::fromString(file_get_contents(self::DIR . $file), $format, $encoding), $detected);
    }


    public function testCp949SamiOnlyParsesWithTheSourceEncoding(): void
    {
        $raw = file_get_contents(self::DIR . "korean-cp949.smi");

        $this->assertSame("기차 안내", Subtitle::fromString($raw, Format::Sami, "CP949")->getMetadata(Subtitle::METADATA_TITLE));
        $this->assertSame("똠방각하가 왔습니다.", Subtitle::fromString($raw, Format::Sami, "CP949")->getCues()[1]->getText());

        $this->expectException(ParsingException::class);
        Subtitle::fromString($raw, Format::Sami);
    }


    public function testWithoutSourceEncodingInvalidUtf8BytesStayAsTheyAre(): void
    {
        $subtitle = Subtitle::fromString(file_get_contents(self::DIR . "french-windows-1252.srt"), Format::SubRip);

        $this->assertSame("Le caf\xE9 est ferm\xE9 \xE0 midi.", $subtitle->getCues()[0]->getText());
    }


    public function testUtf16LeFileWithBomParsesWithoutSourceEncoding(): void
    {
        $raw      = file_get_contents(self::DIR . "notepad-utf-16le.vtt");
        $subtitle = Subtitle::fromStringAutoDetectFormat($raw);
        $cues     = $subtitle->getCues();

        $this->assertCount(3, $cues);
        $this->assertSame([1.0, 3.0, "Grüße aus Köln!"], [$cues[0]->getStart(), $cues[0]->getEnd(), $cues[0]->getText()]);
        $this->assertSame([7.0, 9.0, "<b>Ende</b>"], [$cues[2]->getStart(), $cues[2]->getEnd(), $cues[2]->getText()]);
        $this->assertSame("Saved with Notepad", $subtitle->getComments()[0]["text"]);
        $this->assertEquals($subtitle, Subtitle::fromString($raw, Format::WebVtt, "Windows-1252"));

        $output = $subtitle->toString(Format::WebVtt, self::WINDOWS_OUTPUT);
        $again  = Subtitle::fromString("\xFF\xFE" . iconv("UTF-8", "UTF-16LE", $output), Format::WebVtt);
        $this->assertSame($output, $again->toString(Format::WebVtt, self::WINDOWS_OUTPUT));
        $this->assertSame(
            array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $cues),
            array_map(fn (SubtitleCue $cue) => [$cue->getStart(), $cue->getEnd(), $cue->getText()], $again->getCues())
        );
    }
}
